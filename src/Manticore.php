<?php

namespace Ernestdefoe\Manticore;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The one place that talks to Manticore: its HTTP JSON API (/sql and /bulk)
 * through the Guzzle client Flarum already ships. No pdo_mysql, so a forum on
 * PostgreSQL or SQLite can use it too.
 *
 * 🚨 Every call has a short timeout, and a server that cannot be reached is
 * remembered for DOWN_TTL seconds: searches fall straight back to the database
 * and saves skip, instead of each one waiting out the connect timeout.
 *
 * The password is read here, server-side only; it never reaches the forum.
 */
class Manticore
{
    public const DOWN_KEY = 'ernestdefoe-manticore.down';
    public const NO_FUZZY_KEY = 'ernestdefoe-manticore.no-fuzzy';
    public const LIMIT = 500;

    protected const DOWN_TTL = 30;

    private ?Client $http = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Cache $cache,
        protected LoggerInterface $log,
        protected Config $config
    ) {
    }

    protected function setting(string $key): string
    {
        return trim((string) $this->settings->get("ernestdefoe-manticore.$key"));
    }

    /**
     * Nothing is sent anywhere until a host is entered, so enabling the
     * extension costs a forum nothing per save.
     */
    public function configured(): bool
    {
        return $this->host() !== '';
    }

    protected function host(): string
    {
        return rtrim((string) preg_replace('#^[a-z]+://#i', '', $this->setting('host')), '/');
    }

    /**
     * Table names are built only from [a-z0-9_], so they are safe to place in
     * SQL. The prefix keeps two forums sharing one Manticore apart; left blank
     * it comes from the forum's host name.
     */
    public function table(string $name): string
    {
        $prefix = $this->setting('table_prefix');
        if ($prefix === '') {
            // 🚨 From config.php — Flarum 2 has no `forum_url` setting, and
            // reading one silently gave every forum the same prefix.
            $prefix = $this->config->url()->getHost() ?: 'flarum';
        }
        $prefix = trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($prefix)), '_');
        if ($prefix === '' || ! ctype_alpha($prefix[0])) {
            $prefix = 'f'.$prefix;
        }

        return $prefix.'_'.$name;
    }

    /**
     * Splits what a visitor typed into terms that cannot use Manticore's query
     * syntax: only letters, numbers and marks survive (the rule core applies
     * before MySQL boolean mode), lowercased so the uppercase operators (MAYBE,
     * NEAR, SENTENCE…) cannot form, capped in length and count.
     *
     * @return string[]
     */
    public static function terms(string $value): array
    {
        $terms = preg_split('/[^\p{L}\p{N}\p{M}]+/u', mb_strtolower(mb_substr($value, 0, 200)), -1, PREG_SPLIT_NO_EMPTY);

        return array_slice($terms ?: [], 0, 10);
    }

    /**
     * Runs a SELECT whose `{q}` is replaced by the terms as a quoted SQL
     * string, and returns its rows — or null when Manticore could not answer
     * (the caller then falls back to the database).
     *
     * Typo tolerance (`OPTION fuzzy=1`, Manticore 7+) is tried first. A server
     * that rejects it is remembered and asked without it; there the last term
     * becomes a prefix instead, so results still appear as you type.
     * 🚨 Never both: a wildcard inside a fuzzy query matched almost nothing.
     *
     * @param string[] $terms from terms()
     * @return array<int, array<string, mixed>>|null
     */
    public function select(string $sql, array $terms): ?array
    {
        if (! $this->configured() || empty($terms)) {
            return null;
        }

        $fuzzy = ! $this->cache->get(self::NO_FUZZY_KEY);

        try {
            return $this->sql(str_replace('{q}', self::quote($terms, ! $fuzzy), $sql).($fuzzy ? ' OPTION fuzzy=1' : ''), 2.0)[0]['data'] ?? [];
        } catch (RuntimeException $e) {
            if ($fuzzy && ! $this->cache->get(self::DOWN_KEY)) {
                try {
                    $rows = $this->sql(str_replace('{q}', self::quote($terms, true), $sql), 2.0)[0]['data'] ?? [];
                    $this->cache->put(self::NO_FUZZY_KEY, true, 3600);

                    return $rows;
                } catch (RuntimeException) {
                    // Fall through: the plain query failed as well.
                }
            }
            $this->log->warning('[manticore] search failed, using the database: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param string[] $terms
     */
    protected static function quote(array $terms, bool $prefix): string
    {
        $last = count($terms) - 1;
        if ($prefix && mb_strlen($terms[$last]) >= 2) {
            $terms[$last] .= '*';
        }

        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], implode(' ', $terms))."'";
    }

    /**
     * @return array<mixed>
     */
    public function sql(string $sql, float $timeout = 5.0): array
    {
        return $this->request('/sql?mode=raw', ['form_params' => ['query' => $sql]], $timeout);
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @return array<mixed>
     */
    public function bulk(array $lines, float $timeout = 5.0): array
    {
        $body = '';
        foreach ($lines as $line) {
            $body .= json_encode($line, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
        }

        return $this->request('/bulk', ['headers' => ['Content-Type' => 'application/x-ndjson'], 'body' => $body], $timeout);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    protected function request(string $uri, array $options, float $timeout): array
    {
        if ($this->cache->get(self::DOWN_KEY)) {
            throw new RuntimeException('unreachable');
        }

        try {
            $response = $this->http()->request('POST', $uri, $options + ['timeout' => $timeout]);
        } catch (GuzzleException $e) {
            // http_errors is off, so this is transport only: refused,
            // unresolvable or timed out. Stop asking for a while.
            $this->cache->put(self::DOWN_KEY, true, self::DOWN_TTL);

            throw new RuntimeException('unreachable: '.$e->getMessage(), 0, $e);
        }

        $body = json_decode((string) $response->getBody(), true);
        $error = is_array($body) ? ($body['error'] ?? $body[0]['error'] ?? '') : 'invalid response';

        if ($response->getStatusCode() >= 400 || ! is_array($body) || $error !== '' || ! empty($body['errors'])) {
            throw new RuntimeException(is_string($error) && $error !== '' ? $error : 'HTTP '.$response->getStatusCode());
        }

        return $body;
    }

    protected function http(): Client
    {
        if ($this->http) {
            return $this->http;
        }

        $port = (int) $this->setting('port') ?: 9308;
        $user = $this->setting('username');

        return $this->http = new Client([
            'base_uri' => ($this->setting('scheme') === 'https' ? 'https' : 'http').'://'.$this->host().':'.$port,
            'connect_timeout' => 1,
            'http_errors' => false,
            'allow_redirects' => false,
            'auth' => $user !== '' ? [$user, (string) $this->settings->get('ernestdefoe-manticore.password')] : null,
        ]);
    }

    /**
     * For the admin "Test connection" button: always really asks (clears the
     * remembered outage first) and never throws.
     *
     * @return array{ok: bool, version?: string, error?: string}
     */
    public function ping(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'error' => 'not_configured'];
        }

        $this->cache->forget(self::DOWN_KEY);
        $this->cache->forget(self::NO_FUZZY_KEY);

        try {
            $rows = $this->sql("SHOW STATUS LIKE 'version'", 3.0)[0]['data'] ?? [];

            return ['ok' => true, 'version' => strtok((string) ($rows[0]['Value'] ?? ''), ' ') ?: ''];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}

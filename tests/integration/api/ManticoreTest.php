<?php

namespace Ernestdefoe\Manticore\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * No Manticore server runs here. What is tested is everything that must hold
 * without one: who may reach the endpoints, what the forum is told, and that
 * a configured but unreachable server never breaks posting or search.
 */
class ManticoreTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    /** Nothing listens on the discard port, so a connection is refused at once. */
    private const UNREACHABLE_PORT = '9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-manticore');

        $earlier = Carbon::now()->subHour();

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                ['id' => 1, 'title' => 'Sourdough starter', 'created_at' => $earlier, 'last_posted_at' => $earlier, 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1],
                ['id' => 2, 'title' => 'Hidden starter', 'created_at' => $earlier, 'last_posted_at' => $earlier, 'user_id' => 2, 'first_post_id' => 2, 'comment_count' => 1, 'hidden_at' => $earlier],
                ['id' => 3, 'title' => 'Bicycle gears', 'created_at' => $earlier, 'last_posted_at' => $earlier, 'user_id' => 2, 'first_post_id' => 3, 'comment_count' => 1],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Feed it daily</p></t>', 'created_at' => $earlier],
                ['id' => 2, 'discussion_id' => 2, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Secret</p></t>', 'created_at' => $earlier],
                ['id' => 3, 'discussion_id' => 3, 'number' => 1, 'user_id' => 2, 'type' => 'comment', 'content' => '<t><p>Slipping</p></t>', 'created_at' => $earlier],
            ],
        ]);
    }

    private function useManticore(string $host = '127.0.0.1'): void
    {
        $this->setting('ernestdefoe-manticore.host', $host);
        $this->setting('ernestdefoe-manticore.port', self::UNREACHABLE_PORT);
        $this->setting('search_driver_'.Discussion::class, 'manticore');
    }

    private bool $cacheCleared = false;

    /**
     * The driver remembers an outage for half a minute in the cache, which
     * outlives a test; each test starts without another's memory of one.
     */
    protected function send(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        if (! $this->cacheCleared) {
            $this->app()->getContainer()->make(\Illuminate\Contracts\Cache\Repository::class)->flush();
            $this->cacheCleared = true;
        }

        return parent::send($request);
    }

    private function json(string $method, string $path, ?int $actor): array
    {
        $request = $this->request($method, $path, $actor ? ['authenticatedAs' => $actor] : []);
        if (! $actor) {
            // Past the CSRF check, so a guest reaches the permission check itself.
            $request = $request->withAttribute('bypassCsrfToken', true);
        }

        $response = $this->send($request);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function only_an_admin_reaches_the_status_and_rebuild()
    {
        foreach ([['GET', '/api/manticore/status'], ['POST', '/api/manticore/rebuild']] as [$method, $path]) {
            $this->assertSame(403, $this->json($method, $path, null)[0], "Guest: $method $path");
            $this->assertSame(403, $this->json($method, $path, 2)[0], "Member: $method $path");
        }
    }

    #[Test]
    public function an_unconfigured_driver_says_so()
    {
        [$status, $body] = $this->json('GET', '/api/manticore/status', 1);
        $this->assertSame(200, $status);
        $this->assertSame(['ok' => false, 'error' => 'not_configured'], $body);

        $this->assertSame(422, $this->json('POST', '/api/manticore/rebuild', 1)[0]);
    }

    #[Test]
    public function an_unreachable_server_is_reported_not_thrown()
    {
        $this->useManticore();

        [$status, $body] = $this->json('GET', '/api/manticore/status', 1);

        $this->assertSame(200, $status);
        $this->assertFalse($body['ok']);
        $this->assertNotSame('not_configured', $body['error']);
    }

    #[Test]
    public function the_forum_hears_only_which_searches_manticore_answers()
    {
        $this->useManticore();
        $this->setting('ernestdefoe-manticore.password', 'hunter2');

        $body = (string) $this->send($this->request('GET', '/api'))->getBody();

        $this->assertSame(['discussions'], json_decode($body, true)['data']['attributes']['manticoreSearch']);
        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('127.0.0.1', $body);
    }

    #[Test]
    public function search_falls_back_to_the_database_when_manticore_cannot_answer()
    {
        $this->useManticore();
        // Core's LIKE path: InnoDB's FULLTEXT index cannot see rows inside the
        // transaction each test runs in, so MySQL's MATCH would find nothing.
        $this->setting('search_cjk_mode', '1');

        // Lowercase, as it appears in the titles: core's LIKE is case-sensitive on PostgreSQL.
        $response = $this->send($this->request('GET', '/api/discussions')->withQueryParams(['filter' => ['q' => 'starter']]));

        $this->assertSame(200, $response->getStatusCode());
        $titles = array_column(array_column(json_decode((string) $response->getBody(), true)['data'], 'attributes'), 'title');
        $this->assertSame(['Sourdough starter'], $titles, 'The database answers, and still hides what the visitor may not see');
    }

    #[Test]
    public function a_host_typed_with_its_port_breaks_neither_posting_nor_search()
    {
        // The port has its own field, but "host:port" in the host field is an
        // easy slip, and it must not take the forum down with it.
        $this->useManticore('127.0.0.1:9308');

        $this->assertSame(201, $this->reply()->getStatusCode());

        $response = $this->send($this->request('GET', '/api/discussions')->withQueryParams(['filter' => ['q' => 'sourdough']]));
        $this->assertSame(200, $response->getStatusCode());

        [$status, $body] = $this->json('GET', '/api/manticore/status', 1);
        $this->assertSame(200, $status);
        $this->assertFalse($body['ok']);
    }

    private function reply(): \Psr\Http\Message\ResponseInterface
    {
        return $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 2,
            'json' => ['data' => [
                'type' => 'posts',
                'attributes' => ['content' => 'Another reply'],
                'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
            ]],
        ]));
    }

    #[Test]
    public function posting_still_works_when_manticore_is_down()
    {
        $this->useManticore();

        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 2,
            'json' => ['data' => [
                'type' => 'posts',
                'attributes' => ['content' => 'Another reply'],
                'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]],
            ]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }
}

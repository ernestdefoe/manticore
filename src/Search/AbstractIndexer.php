<?php

namespace Ernestdefoe\Manticore\Search;

use Ernestdefoe\Manticore\Manticore;
use Flarum\Database\AbstractModel;
use Flarum\Search\IndexerInterface;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Shared plumbing for the real-time tables. A subclass names its table, its
 * columns, the query for a full build and how one model becomes a document.
 *
 * 🚨 A save never asks whether the table exists first: it writes, and only a
 * "table absent" answer creates the table and writes again. Every post, reply
 * and edit is indexed — inside the visitor's request on the sync queue — so
 * the hot path is exactly one round trip.
 *
 * A failed save is logged and swallowed: a search server that blips must never
 * stop someone posting. A rebuild picks up anything missed.
 */
abstract class AbstractIndexer implements IndexerInterface
{
    protected const CHUNK = 500;

    /**
     * 🚨 The columns a document is built from. Core re-indexes on EVERY
     * update: a discussion on each reply (last_posted_at, counts), a user on
     * each visit (last_seen_at). A save whose change touched none of these is
     * skipped, so those cost no round trip at all.
     */
    protected const FIELDS = [];

    public function __construct(
        protected Manticore $manticore,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Column list for CREATE TABLE. Text is `indexed` only (not stored):
     * Flarum loads the content from its own database, so Manticore keeps no
     * second copy of it.
     */
    abstract protected function columns(): string;

    /** @return Builder<covariant AbstractModel> */
    abstract protected function buildQuery(): Builder;

    /**
     * @return array<string, scalar>|null null when the model must not be in the index
     */
    abstract protected function document(AbstractModel $model): ?array;

    public function save(array $models): void
    {
        if (! $this->manticore->configured() || empty($models)) {
            return;
        }

        $docs = [];
        $gone = [];
        foreach ($models as $model) {
            if (! $model->wasRecentlyCreated && ! $model->wasChanged(static::FIELDS)) {
                continue;
            }
            $doc = $this->document($model);
            if ($doc === null) {
                $gone[] = (int) $model->getKey();
            } else {
                $docs[(int) $model->getKey()] = $doc;
            }
        }

        if (empty($docs) && empty($gone)) {
            return;
        }

        try {
            $this->write($docs, 5.0);
            $this->remove('id', $gone);
        } catch (RuntimeException $e) {
            $this->log->warning('[manticore] '.static::index().' save failed: '.$e->getMessage());
        }
    }

    public function delete(array $models): void
    {
        if (! $this->manticore->configured() || empty($models)) {
            return;
        }

        try {
            $this->remove('id', array_map(fn ($m) => (int) $m->getKey(), $models));
        } catch (RuntimeException $e) {
            $this->log->warning('[manticore] '.static::index().' delete failed: '.$e->getMessage());
        }
    }

    /**
     * Drops and refills the table. Errors are thrown, so the console command
     * and the queue report a failed rebuild instead of a silent empty index.
     */
    public function build(): void
    {
        if (! $this->manticore->configured()) {
            return;
        }

        $this->flush();
        $this->create();

        $this->buildQuery()->chunkById(self::CHUNK, function ($models) {
            $docs = [];
            foreach ($models as $model) {
                if (($doc = $this->document($model)) !== null) {
                    $docs[(int) $model->getKey()] = $doc;
                }
            }
            $this->write($docs, 30.0);
        });
    }

    public function flush(): void
    {
        if ($this->manticore->configured()) {
            $this->manticore->sql('DROP TABLE IF EXISTS '.$this->table());
        }
    }

    protected function table(): string
    {
        return $this->manticore->table(static::index());
    }

    protected function create(): void
    {
        $this->manticore->sql('CREATE TABLE IF NOT EXISTS '.$this->table().' ('.$this->columns().") min_infix_len='2'");
    }

    /**
     * @param array<int, array<string, scalar>> $docs keyed by id
     */
    protected function write(array $docs, float $timeout): void
    {
        if (empty($docs)) {
            return;
        }

        $lines = [];
        foreach ($docs as $id => $doc) {
            $lines[] = ['replace' => ['index' => $this->table(), 'id' => $id, 'doc' => $doc]];
        }

        try {
            $this->manticore->bulk($lines, $timeout);
        } catch (RuntimeException $e) {
            if (! str_contains($e->getMessage(), 'absent')) {
                throw $e;
            }
            $this->create();
            $this->manticore->bulk($lines, $timeout);
        }
    }

    /**
     * @param int[] $ids
     */
    protected function remove(string $column, array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return;
        }

        try {
            $this->manticore->sql('DELETE FROM '.$this->table()." WHERE $column IN (".implode(',', $ids).')');
        } catch (RuntimeException $e) {
            // Nothing to delete from a table that was never built.
            if (! preg_match('/unknown|no such|absent/i', $e->getMessage())) {
                throw $e;
            }
        }
    }
}

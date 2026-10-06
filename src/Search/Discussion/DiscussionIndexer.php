<?php

namespace Ernestdefoe\Manticore\Search\Discussion;

use Ernestdefoe\Manticore\Search\AbstractIndexer;
use Ernestdefoe\Manticore\Search\Post\PostIndexer;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Titles only. Post text lives in the posts table (one row per post, carrying
 * its discussion_id), and discussion search groups post matches by discussion.
 * So a reply writes one small row instead of re-reading its whole thread.
 */
class DiscussionIndexer extends AbstractIndexer
{
    protected const FIELDS = ['title', 'hidden_at'];

    public static function index(): string
    {
        return 'discussions';
    }

    protected function columns(): string
    {
        return 'title text indexed';
    }

    protected function buildQuery(): Builder
    {
        return Discussion::query()->whereNull('hidden_at')->select('id', 'title', 'hidden_at');
    }

    protected function document(AbstractModel $model): ?array
    {
        /** @var Discussion $model */
        return $model->hidden_at === null ? ['title' => (string) $model->title] : null;
    }

    /**
     * A discussion deleted for good takes its posts' rows with it (the
     * database cascade fires no post events). A hidden one keeps them, so
     * restoring it needs no re-read of the thread.
     */
    public function delete(array $models): void
    {
        parent::delete($models);

        $gone = array_map(fn ($d) => (int) $d->id, array_filter($models, fn ($d) => ! $d->exists));
        if (empty($gone) || ! $this->manticore->configured()) {
            return;
        }

        try {
            $this->manticore->sql('DELETE FROM '.$this->manticore->table(PostIndexer::index()).' WHERE discussion_id IN ('.implode(',', $gone).')');
        } catch (RuntimeException $e) {
            $this->log->warning('[manticore] posts of deleted discussions not removed: '.$e->getMessage());
        }
    }
}

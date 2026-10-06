<?php

namespace Ernestdefoe\Manticore\Search\Post;

use Ernestdefoe\Manticore\Search\AbstractIndexer;
use Flarum\Database\AbstractModel;
use Flarum\Post\Post;
use Illuminate\Database\Eloquent\Builder;

/**
 * One row per visible comment post. Serves post search directly, and
 * discussion search through discussion_id.
 */
class PostIndexer extends AbstractIndexer
{
    protected const FIELDS = ['content', 'hidden_at', 'type', 'discussion_id'];

    protected const MAX_CONTENT = 50000;

    public static function index(): string
    {
        return 'posts';
    }

    protected function columns(): string
    {
        return 'content text indexed, discussion_id bigint';
    }

    protected function buildQuery(): Builder
    {
        return Post::query()->where('type', 'comment')->whereNull('hidden_at');
    }

    protected function document(AbstractModel $model): ?array
    {
        /** @var Post $model */
        if ($model->type !== 'comment' || $model->hidden_at !== null) {
            return null;
        }

        return [
            'content' => mb_substr(strip_tags((string) $model->content), 0, self::MAX_CONTENT),
            'discussion_id' => (int) $model->discussion_id,
        ];
    }
}

<?php

namespace Ernestdefoe\Manticore\Job;

use Ernestdefoe\Manticore\Search\Discussion\DiscussionIndexer;
use Ernestdefoe\Manticore\Search\Post\PostIndexer;
use Ernestdefoe\Manticore\Search\User\UserIndexer;
use Flarum\Queue\AbstractJob;
use Illuminate\Contracts\Container\Container;

/**
 * Full rebuild of every table, off the request when a real queue worker runs.
 */
class RebuildJob extends AbstractJob
{
    public int $tries = 1;
    public int $timeout = 3600;

    public function handle(Container $container): void
    {
        foreach ([DiscussionIndexer::class, PostIndexer::class, UserIndexer::class] as $indexer) {
            $container->make($indexer)->build();
        }
    }
}

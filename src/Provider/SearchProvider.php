<?php

namespace Ernestdefoe\Manticore\Provider;

use Ernestdefoe\Manticore\Manticore;
use Ernestdefoe\Manticore\Search\Discussion\ManticoreDiscussionSearcher;
use Ernestdefoe\Manticore\Search\Post\ManticorePostSearcher;
use Ernestdefoe\Manticore\Search\User\ManticoreUserSearcher;
use Flarum\Discussion\Search\DiscussionSearcher;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Post\Filter\PostSearcher;
use Flarum\User\Search\UserSearcher;

/**
 * Filters and mutators registered against core's searchers (by core and by
 * extensions such as flarum/tags' `tag:`) are mirrored onto ours, so a
 * Manticore search honours the same refinements as a database one.
 * Permissions never depend on this: getQuery() applies whereVisibleTo.
 */
class SearchProvider extends AbstractServiceProvider
{
    protected const MIRROR = [
        ManticoreDiscussionSearcher::class => DiscussionSearcher::class,
        ManticoreUserSearcher::class => UserSearcher::class,
        ManticorePostSearcher::class => PostSearcher::class,
    ];

    public function register(): void
    {
        $this->container->singleton(Manticore::class);
    }

    /**
     * In boot(), not register(): extensions that enable after this one add
     * their filters later, and a mirror taken at register time missed them.
     */
    public function boot(): void
    {
        $this->container->extend('flarum.search.filters', function (array $filters) {
            foreach (self::MIRROR as $mine => $parent) {
                $filters[$mine] = array_values(array_unique(array_merge($filters[$mine] ?? [], $filters[$parent] ?? [])));
            }

            return $filters;
        });

        $this->container->extend('flarum.search.mutators', function (array $mutators) {
            foreach (self::MIRROR as $mine => $parent) {
                $mutators[$mine] = array_merge($mutators[$mine] ?? [], $mutators[$parent] ?? []);
            }

            return $mutators;
        });
    }
}

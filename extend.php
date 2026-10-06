<?php

use Ernestdefoe\Manticore\Api\Controller\RebuildController;
use Ernestdefoe\Manticore\Api\Controller\StatusController;
use Ernestdefoe\Manticore\Console\IndexCommand;
use Ernestdefoe\Manticore\Provider\SearchProvider;
use Ernestdefoe\Manticore\Search\Discussion\DiscussionIndexer;
use Ernestdefoe\Manticore\Search\Discussion\FulltextFilter as DiscussionFulltextFilter;
use Ernestdefoe\Manticore\Search\Discussion\ManticoreDiscussionSearcher;
use Ernestdefoe\Manticore\Search\ManticoreSearchDriver;
use Ernestdefoe\Manticore\Search\Post\FulltextFilter as PostFulltextFilter;
use Ernestdefoe\Manticore\Search\Post\ManticorePostSearcher;
use Ernestdefoe\Manticore\Search\Post\PostIndexer;
use Ernestdefoe\Manticore\Search\User\FulltextFilter as UserFulltextFilter;
use Ernestdefoe\Manticore\Search\User\ManticoreUserSearcher;
use Ernestdefoe\Manticore\Search\User\UserIndexer;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema\Attribute;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/less/forum.less'),

    new Extend\Locales(__DIR__.'/locale'),

    /*
     * Which search tabs Manticore answers, for the badge in the search modal.
     * Resource names only — connection details and the password stay on the
     * server.
     */
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Attribute::make('manticoreSearch')->get(function () {
                $settings = resolve(SettingsRepositoryInterface::class);

                return array_keys(array_filter(
                    ['discussions' => Discussion::class, 'users' => User::class, 'posts' => Post::class],
                    fn (string $model) => $settings->get("search_driver_$model") === ManticoreSearchDriver::name()
                ));
            }),
        ]),

    (new Extend\SearchDriver(ManticoreSearchDriver::class))
        ->addSearcher(Discussion::class, ManticoreDiscussionSearcher::class)
        ->setFulltext(ManticoreDiscussionSearcher::class, DiscussionFulltextFilter::class)
        ->addSearcher(User::class, ManticoreUserSearcher::class)
        ->setFulltext(ManticoreUserSearcher::class, UserFulltextFilter::class)
        ->addSearcher(Post::class, ManticorePostSearcher::class)
        ->setFulltext(ManticorePostSearcher::class, PostFulltextFilter::class),

    /*
     * 🚨 CommentPost as well as Post: core observes the exact class it is
     * given, and a reply is saved as a CommentPost, whose model events a
     * Post observer never hears. Registered under Post alone, no new post,
     * edit or delete ever reached the index.
     */
    (new Extend\SearchIndex())
        ->indexer(Discussion::class, DiscussionIndexer::class)
        ->indexer(Post::class, PostIndexer::class)
        ->indexer(CommentPost::class, PostIndexer::class)
        ->indexer(User::class, UserIndexer::class),

    (new Extend\ServiceProvider())
        ->register(SearchProvider::class),

    (new Extend\Console())
        ->command(IndexCommand::class),

    (new Extend\Routes('api'))
        ->get('/manticore/status', 'ernestdefoe-manticore.status', StatusController::class)
        ->post('/manticore/rebuild', 'ernestdefoe-manticore.rebuild', RebuildController::class),
];

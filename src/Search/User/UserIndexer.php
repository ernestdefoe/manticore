<?php

namespace Ernestdefoe\Manticore\Search\User;

use Ernestdefoe\Manticore\Search\AbstractIndexer;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Username and display name only — never email or anything private.
 */
class UserIndexer extends AbstractIndexer
{
    protected const FIELDS = ['username', 'nickname'];

    public static function index(): string
    {
        return 'users';
    }

    protected function columns(): string
    {
        return 'username text indexed, display_name text indexed';
    }

    protected function buildQuery(): Builder
    {
        return User::query();
    }

    protected function document(AbstractModel $model): ?array
    {
        /** @var User $model */
        return [
            'username' => (string) $model->username,
            'display_name' => (string) $model->display_name,
        ];
    }
}

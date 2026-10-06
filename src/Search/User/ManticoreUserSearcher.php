<?php

namespace Ernestdefoe\Manticore\Search\User;

use Flarum\User\Search\UserSearcher;

/**
 * Its own class so this driver owns its fulltext mapping without touching the
 * database driver's. Visibility (getQuery() → whereVisibleTo), filters, sort
 * and pagination are all core's.
 */
class ManticoreUserSearcher extends UserSearcher
{
}

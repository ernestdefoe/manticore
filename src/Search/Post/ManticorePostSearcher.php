<?php

namespace Ernestdefoe\Manticore\Search\Post;

use Flarum\Post\Filter\PostSearcher;

/**
 * Its own class so this driver owns its fulltext mapping without touching the
 * database driver's. Visibility (getQuery() → whereVisibleTo), filters, sort
 * and pagination are all core's.
 */
class ManticorePostSearcher extends PostSearcher
{
}

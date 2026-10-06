<?php

namespace Ernestdefoe\Manticore\Search\Discussion;

use Flarum\Discussion\Search\DiscussionSearcher;

/**
 * Its own class so this driver owns its fulltext mapping without touching the
 * database driver's. Visibility (getQuery() → whereVisibleTo), filters, sort
 * and pagination are all core's.
 */
class ManticoreDiscussionSearcher extends DiscussionSearcher
{
}

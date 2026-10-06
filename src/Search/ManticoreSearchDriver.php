<?php

namespace Ernestdefoe\Manticore\Search;

use Flarum\Search\AbstractDriver;

/**
 * Chosen per resource through core's `search_driver_<ModelClass>` setting.
 * Core routes only text searches here; filtering and browsing stay on the
 * database driver.
 */
class ManticoreSearchDriver extends AbstractDriver
{
    public static function name(): string
    {
        return 'manticore';
    }
}

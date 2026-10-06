<?php

namespace Ernestdefoe\Manticore\Search\User;

use Ernestdefoe\Manticore\Manticore;
use Ernestdefoe\Manticore\Search\AbstractFulltextFilter;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\User\Search\FulltextFilter as DatabaseFulltextFilter;

class FulltextFilter extends AbstractFulltextFilter
{
    protected function fallback(): string
    {
        return DatabaseFulltextFilter::class;
    }

    protected function rank(array $terms, DatabaseSearchState $state): ?array
    {
        $rows = $this->manticore->select('SELECT id FROM '.$this->manticore->table('users')
            .' WHERE MATCH({q}) LIMIT '.Manticore::LIMIT, $terms);

        return $rows === null ? null : array_map(fn ($row) => (int) $row['id'], $rows);
    }
}

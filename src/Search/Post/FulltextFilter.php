<?php

namespace Ernestdefoe\Manticore\Search\Post;

use Ernestdefoe\Manticore\Manticore;
use Ernestdefoe\Manticore\Search\AbstractFulltextFilter;
use Flarum\Post\Filter\FulltextFilter as DatabaseFulltextFilter;
use Flarum\Search\Database\DatabaseSearchState;

class FulltextFilter extends AbstractFulltextFilter
{
    protected function fallback(): string
    {
        return DatabaseFulltextFilter::class;
    }

    protected function rank(array $terms, DatabaseSearchState $state): ?array
    {
        $rows = $this->manticore->select('SELECT id FROM '.$this->manticore->table('posts')
            .' WHERE MATCH({q}) LIMIT '.Manticore::LIMIT, $terms);

        return $rows === null ? null : array_map(fn ($row) => (int) $row['id'], $rows);
    }
}

<?php

namespace Ernestdefoe\Manticore\Search;

use Ernestdefoe\Manticore\Manticore;
use Flarum\Search\AbstractFulltextFilter as BaseFulltextFilter;
use Flarum\Search\Database\DatabaseSearchState;
use Flarum\Search\SearchState;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;

/**
 * Manticore only ranks: it returns candidate ids in relevance order, and the
 * searcher's own query — already scoped by whereVisibleTo (private
 * discussions, hidden and unapproved posts, restricted tags) — is narrowed to
 * those ids. Nothing the actor cannot see can come back.
 *
 * When Manticore cannot answer (not configured, unreachable, table not built)
 * the search is handed to core's database filter, so the search box keeps
 * working instead of going blank.
 */
abstract class AbstractFulltextFilter extends BaseFulltextFilter
{
    public function __construct(
        protected Manticore $manticore,
        protected Container $container
    ) {
    }

    /**
     * @return class-string<BaseFulltextFilter> core's database filter for this resource
     */
    abstract protected function fallback(): string;

    /**
     * @param string[] $terms
     * @return int[]|null ids in relevance order, or null when Manticore could not answer
     */
    abstract protected function rank(array $terms, DatabaseSearchState $state): ?array;

    public function search(SearchState $state, string $value): void
    {
        /** @var DatabaseSearchState $state */
        $terms = Manticore::terms($value);
        $query = $state->getQuery();

        if (empty($terms)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $ids = $this->rank($terms, $state);

        if ($ids === null) {
            $this->container->make($this->fallback())->search($state, $value);

            return;
        }

        if (empty($ids)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $column = $query->getModel()->getQualifiedKeyName();
        $query->whereIn($column, $ids);

        // Keep Manticore's order unless the visitor chose a sort. A portable
        // CASE (MySQL, MariaDB, PostgreSQL, SQLite), column wrapped by the
        // grammar so the table prefix is applied, ids bound.
        $state->setDefaultSort(function (Builder $q) use ($column, $ids) {
            $sql = 'CASE '.$q->getQuery()->getGrammar()->wrap($column);
            foreach (array_keys($ids) as $position) {
                $sql .= ' WHEN ? THEN '.(int) $position;
            }
            $q->orderByRaw($sql.' END', $ids);
        });
    }
}

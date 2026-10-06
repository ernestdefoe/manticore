<?php

namespace Ernestdefoe\Manticore\Search\Discussion;

use Ernestdefoe\Manticore\Manticore;
use Ernestdefoe\Manticore\Search\AbstractFulltextFilter;
use Flarum\Discussion\Search\FulltextFilter as DatabaseFulltextFilter;
use Flarum\Post\Post;
use Flarum\Search\Database\DatabaseSearchState;

/**
 * Two small queries: titles, and posts grouped by discussion (best post per
 * group). A title match counts double. The best post becomes the result's
 * mostRelevantPost — but only if this actor may see it; otherwise the first
 * post is used, exactly like core's database driver.
 */
class FulltextFilter extends AbstractFulltextFilter
{
    protected function fallback(): string
    {
        return DatabaseFulltextFilter::class;
    }

    protected function rank(array $terms, DatabaseSearchState $state): ?array
    {
        $limit = Manticore::LIMIT;
        $titles = $this->manticore->select('SELECT id, weight() AS w FROM '.$this->manticore->table('discussions')
            .' WHERE MATCH({q}) LIMIT '.$limit, $terms);
        $posts = $titles === null ? null : $this->manticore->select('SELECT discussion_id, id, weight() AS w FROM '
            .$this->manticore->table('posts').' WHERE MATCH({q}) GROUP BY discussion_id'
            .' WITHIN GROUP ORDER BY weight() DESC ORDER BY w DESC LIMIT '.$limit, $terms);

        if ($posts === null) {
            return null;
        }

        $score = [];
        $best = [];
        foreach ($titles as $row) {
            $score[(int) $row['id']] = 2 * (int) $row['w'];
        }
        foreach ($posts as $row) {
            $id = (int) $row['discussion_id'];
            $score[$id] = ($score[$id] ?? 0) + (int) $row['w'];
            $best[$id] = (int) $row['id'];
        }
        arsort($score);
        $ids = array_slice(array_keys($score), 0, $limit);

        $this->selectMostRelevantPost($state, $best);

        return $ids;
    }

    /**
     * @param array<int, int> $best discussion id => best matching post id
     */
    protected function selectMostRelevantPost(DatabaseSearchState $state, array $best): void
    {
        $query = $state->getQuery();
        $grammar = $query->getQuery()->getGrammar();

        $visible = empty($best) ? [] : array_flip(
            Post::whereVisibleTo($state->getActor())->whereIn('posts.id', array_values($best))->pluck('posts.id')->all()
        );

        $sql = 'CASE '.$grammar->wrap('discussions.id');
        $bindings = [];
        foreach ($best as $discussionId => $postId) {
            if (isset($visible[$postId])) {
                $sql .= ' WHEN ? THEN ?';
                array_push($bindings, $discussionId, $postId);
            }
        }

        $query->selectRaw(
            ($bindings ? $sql.' ELSE ' : '').$grammar->wrap('discussions.first_post_id').($bindings ? ' END' : '').' AS most_relevant_post_id',
            $bindings
        );
    }
}

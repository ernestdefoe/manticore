<?php

namespace Ernestdefoe\Manticore\Console;

use Ernestdefoe\Manticore\Manticore;
use Ernestdefoe\Manticore\Search\Discussion\DiscussionIndexer;
use Ernestdefoe\Manticore\Search\Post\PostIndexer;
use Ernestdefoe\Manticore\Search\User\UserIndexer;
use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

class IndexCommand extends AbstractCommand
{
    public function __construct(
        protected Manticore $manticore,
        protected DiscussionIndexer $discussions,
        protected PostIndexer $posts,
        protected UserIndexer $users
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('manticore:index')
            ->setDescription('Rebuild (or drop) the Manticore search tables: discussions, posts and users.')
            ->addOption('flush', null, InputOption::VALUE_NONE, 'Drop the tables instead of rebuilding them.');
    }

    protected function fire(): int
    {
        if (! $this->manticore->configured()) {
            $this->error('Manticore is not configured. Enter its host under Admin → Manticore Search.');

            return 1;
        }

        $flush = (bool) $this->input->getOption('flush');

        foreach (['discussions' => $this->discussions, 'posts' => $this->posts, 'users' => $this->users] as $name => $indexer) {
            $this->info(($flush ? 'Dropping ' : 'Rebuilding ').$name.'…');
            $flush ? $indexer->flush() : $indexer->build();
        }

        $this->info('Done.');

        return 0;
    }
}

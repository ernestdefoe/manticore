<?php

namespace Ernestdefoe\Manticore\Api\Controller;

use Ernestdefoe\Manticore\Job\RebuildJob;
use Ernestdefoe\Manticore\Manticore;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Queue\Queue;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RebuildController implements RequestHandlerInterface
{
    public function __construct(
        protected Manticore $manticore,
        protected Queue $queue
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        if (! $this->manticore->configured()) {
            return new JsonResponse(['error' => 'not_configured'], 422);
        }

        $this->queue->push(new RebuildJob());

        return new JsonResponse(['status' => 'queued'], 202);
    }
}

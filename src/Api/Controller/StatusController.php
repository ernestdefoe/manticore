<?php

namespace Ernestdefoe\Manticore\Api\Controller;

use Ernestdefoe\Manticore\Manticore;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class StatusController implements RequestHandlerInterface
{
    public function __construct(
        protected Manticore $manticore
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse($this->manticore->ping());
    }
}

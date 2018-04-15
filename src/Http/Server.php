<?php

/*
 * This file is part of Flarum.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flarum\Http;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Zend\Diactoros\Server as DiactorosServer;
use Zend\Stratigility\MiddlewarePipeInterface;

class Server implements Middleware, Handler
{
    protected $middleware;

    public function __construct(MiddlewarePipeInterface $middleware)
    {
        $this->middleware = $middleware;
    }

    public function listen()
    {
        DiactorosServer::createServer(
            [$this, 'handle'],
            $_SERVER,
            $_GET,
            $_POST,
            $_COOKIE,
            $_FILES
        )->listen();
    }

    /**
     * Use as PSR-15 middleware.
     */
    public function process(Request $request, Handler $handler): Response
    {
        return $this->middleware->process($request, $handler);
    }

    /**
     * Use as PSR-15 request handler.
     */
    public function handle(Request $request): Response
    {
        return $this->middleware->handle($request);
    }
}

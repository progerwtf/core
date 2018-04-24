<?php

/*
 * This file is part of Flarum.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flarum\Foundation;

use Flarum\Database\Console\GenerateMigrationCommand;
use Flarum\Database\Console\MigrateCommand;
use Flarum\Database\Console\ResetCommand;
use Flarum\Foundation\Console\CacheClearCommand;
use Flarum\Foundation\Console\InfoCommand;
use Flarum\Http\Middleware\DispatchRoute;
use Zend\Diactoros\Response\HtmlResponse;
use Zend\Stratigility\MiddlewarePipe;
use function Zend\Stratigility\middleware;
use function Zend\Stratigility\path;

class InstalledApp implements AppInterface
{
    /**
     * @var Application
     */
    protected $laravel;

    /**
     * @var array
     */
    protected $config;

    public function __construct(Application $laravel, array $config)
    {
        $this->laravel = $laravel;
        $this->config = $config;
    }

    /**
     * @return \Zend\Stratigility\MiddlewarePipeInterface
     */
    public function getMiddleware()
    {
        if ($this->inMaintenanceMode()) {
            return $this->getMaintenanceMiddleware();
        } elseif ($this->needsUpdate()) {
            return $this->getUpdaterMiddleware();
        }

        $pipe = new MiddlewarePipe;

        $api = parse_url($this->laravel->url('api'), PHP_URL_PATH);
        $pipe->pipe(path($api, $this->laravel->make('flarum.api.middleware')));

        $admin = parse_url($this->laravel->url('admin'), PHP_URL_PATH);
        $pipe->pipe(path($admin, $this->laravel->make('flarum.admin.middleware')));

        $forum = parse_url($this->laravel->url(''), PHP_URL_PATH) ?: '/';
        $pipe->pipe(path($forum, $this->laravel->make('flarum.forum.middleware')));

        return $pipe;
    }

    private function inMaintenanceMode(): bool
    {
        return $this->config['offline'] ?? false;
    }

    /**
     * @return \Zend\Stratigility\MiddlewarePipeInterface
     */
    private function getMaintenanceMiddleware()
    {
        $pipe = new MiddlewarePipe;

        $pipe->pipe(middleware(function () {
            // FIXME: Fix path to 503.html
            // TODO: FOR API render JSON-API error document for HTTP 503
            return new HtmlResponse(
                file_get_contents(__DIR__.'/../../503.html'), 503
            );
        }));

        return $pipe;
    }

    private function needsUpdate(): bool
    {
        return false;
    }

    /**
     * @return \Zend\Stratigility\MiddlewarePipeInterface
     */
    public function getUpdaterMiddleware()
    {
        $pipe = new MiddlewarePipe;
        $pipe->pipe(
            $this->laravel->make(
                DispatchRoute::class,
                ['routes' => $this->laravel->make('flarum.update.routes')]
            )
        );

        return $pipe;
    }

    /**
     * @return \Symfony\Component\Console\Command\Command[]
     */
    public function getConsoleCommands()
    {
        return [
            $this->laravel->make(GenerateMigrationCommand::class),
            $this->laravel->make(InfoCommand::class, ['config' => $this->config]),
            $this->laravel->make(MigrateCommand::class),
            $this->laravel->make(ResetCommand::class),
            $this->laravel->make(CacheClearCommand::class),
        ];
    }
}

<?php

namespace Flarum\Install;

use Flarum\Foundation\AppInterface;
use Flarum\Foundation\Application;
use Flarum\Http\Middleware\DispatchRoute;
use Flarum\Install\Console\InstallCommand;
use Zend\Stratigility\MiddlewarePipe;

class Installer implements AppInterface
{
    /**
     * @var Application
     */
    protected $laravel;

    public function __construct(Application $laravel)
    {
        $this->laravel = $laravel;
    }

    /**
     * @return \Zend\Stratigility\MiddlewarePipeInterface
     */
    public function getMiddleware()
    {
        // FIXME: Re-enable HandleErrors middleware, if possible
        // (Right now it tries to resolve a database connection because of the injected settings repo instance)
        // We could register a different settings repo when Flarum is not installed
        //$pipe->pipe($this->app->make(HandleErrors::class, ['debug' => true]));
        //$pipe->pipe($this->app->make(StartSession::class));

        $pipe = new MiddlewarePipe;
        $pipe->pipe(
            $this->laravel->make(
                DispatchRoute::class,
                ['routes' => $this->laravel->make('flarum.install.routes')]
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
            $this->laravel->make(InstallCommand::class),
        ];
    }
}

<?php

/*
 * This file is part of Flarum.
 *
 * (c) Toby Zerner <toby.zerner@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flarum\Forum;

use Flarum\Event\ConfigureForumRoutes;
use Flarum\Event\ConfigureMiddleware;
use Flarum\Extension\Event\Disabled;
use Flarum\Extension\Event\Enabled;
use Flarum\Foundation\AbstractServiceProvider;
use Flarum\Foundation\Application;
use Flarum\Http\Middleware as HttpMiddleware;
use Flarum\Http\RouteCollection;
use Flarum\Http\RouteHandlerFactory;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use Symfony\Component\Translation\TranslatorInterface;
use Zend\Stratigility\MiddlewarePipe;

class ForumServiceProvider extends AbstractServiceProvider
{
    /**
     * {@inheritdoc}
     */
    public function register()
    {
        $this->app->extend(UrlGenerator::class, function (UrlGenerator $url) {
            return $url->addCollection('forum', $this->app->make('flarum.forum.routes'));
        });

        $this->app->singleton('flarum.forum.routes', function () {
            return new RouteCollection;
        });

        $this->app->singleton('flarum.forum.middleware', function (Application $app) {
            $pipe = new MiddlewarePipe;

            // All requests should first be piped through our global error handler
            $debugMode = ! $app->isUpToDate() || $app->inDebugMode();
            $pipe->pipe($app->make(HttpMiddleware\HandleErrors::class, ['debug' => $debugMode]));

            $pipe->pipe($app->make(HttpMiddleware\ParseJsonBody::class));
            $pipe->pipe($app->make(HttpMiddleware\CollectGarbage::class));
            $pipe->pipe($app->make(HttpMiddleware\StartSession::class));
            $pipe->pipe($app->make(HttpMiddleware\RememberFromCookie::class));
            $pipe->pipe($app->make(HttpMiddleware\AuthenticateWithSession::class));
            $pipe->pipe($app->make(HttpMiddleware\SetLocale::class));
            $pipe->pipe($app->make(HttpMiddleware\ShareErrorsFromSession::class));

            event(new ConfigureMiddleware($pipe, 'forum'));

            return $pipe;
        });

        $this->app->afterResolving('flarum.forum.middleware', function (MiddlewarePipe $pipe) {
            $pipe->pipe(new HttpMiddleware\DispatchRoute($this->app->make('flarum.forum.routes')));
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot()
    {
        $this->populateRoutes($this->app->make('flarum.forum.routes'));

        $this->loadViewsFrom(__DIR__.'/../../views', 'flarum.forum');

        $this->app->make('view')->share([
            'translator' => $this->app->make(TranslatorInterface::class),
            'settings' => $this->app->make(SettingsRepositoryInterface::class)
        ]);

        $this->flushWebAppAssetsWhenThemeChanged();

        $this->flushWebAppAssetsWhenExtensionsChanged();
    }

    /**
     * Populate the forum client routes.
     *
     * @param RouteCollection $routes
     */
    protected function populateRoutes(RouteCollection $routes)
    {
        $factory = $this->app->make(RouteHandlerFactory::class);

        $callback = include __DIR__.'/routes.php';
        $callback($routes, $factory);

        $this->app->make('events')->fire(
            new ConfigureForumRoutes($routes, $factory)
        );

        $defaultRoute = $this->app->make('flarum.settings')->get('default_route');

        if (isset($routes->getRouteData()[0]['GET'][$defaultRoute])) {
            $toDefaultController = $routes->getRouteData()[0]['GET'][$defaultRoute];
        } else {
            $toDefaultController = $factory->toController(Controller\IndexController::class);
        }

        $routes->get(
            '/',
            'default',
            $toDefaultController
        );
    }

    protected function flushWebAppAssetsWhenThemeChanged()
    {
        $this->app->make('events')->listen(Saved::class, function (Saved $event) {
            if (preg_match('/^theme_|^custom_less$/i', $event->key)) {
                $this->getWebAppAssets()->flushCss();
            }
        });
    }

    protected function flushWebAppAssetsWhenExtensionsChanged()
    {
        $events = $this->app->make('events');

        $events->listen(Enabled::class, [$this, 'flushWebAppAssets']);
        $events->listen(Disabled::class, [$this, 'flushWebAppAssets']);
    }

    public function flushWebAppAssets()
    {
        $this->getWebAppAssets()->flush();
    }

    /**
     * @return \Flarum\Frontend\FrontendAssets
     */
    protected function getWebAppAssets()
    {
        return $this->app->make(Frontend::class)->getAssets();
    }
}

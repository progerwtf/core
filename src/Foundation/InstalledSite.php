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

use Flarum\Admin\AdminServiceProvider;
use Flarum\Api\ApiServiceProvider;
use Flarum\Bus\BusServiceProvider as BusProvider;
use Flarum\Database\DatabaseServiceProvider;
use Flarum\Database\MigrationServiceProvider;
use Flarum\Discussion\DiscussionServiceProvider;
use Flarum\Extension\ExtensionServiceProvider;
use Flarum\Formatter\FormatterServiceProvider;
use Flarum\Forum\ForumServiceProvider;
use Flarum\Group\GroupServiceProvider;
use Flarum\Locale\LocaleServiceProvider;
use Flarum\Notification\NotificationServiceProvider;
use Flarum\Post\PostServiceProvider;
use Flarum\Search\SearchServiceProvider;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Settings\SettingsServiceProvider;
use Flarum\User\UserServiceProvider;
use Illuminate\Bus\BusServiceProvider;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Hashing\HashServiceProvider;
use Illuminate\Mail\MailServiceProvider;
use Illuminate\Validation\ValidationServiceProvider;
use Illuminate\View\ViewServiceProvider;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class InstalledSite implements SiteInterface
{
    /**
     * @var Application
     */
    protected $app;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $publicPath;

    /**
     * @var string
     */
    protected $storagePath;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var callable[]
     */
    protected $extenders = [];

    public function __construct($basePath, $publicPath, array $config)
    {
        $this->basePath = $basePath;
        $this->publicPath = $publicPath;
        $this->config = $config;
    }

    /**
     * Create and boot a Flarum application instance.
     *
     * @return AppInterface
     */
    public function bootApp(): AppInterface
    {
        return new InstalledApp(
            $this->bootLaravel(),
            $this->config
        );
    }

    /**
     * @param $storagePath
     * @return static
     */
    public function setStoragePath($storagePath)
    {
        $this->storagePath = $storagePath;

        return $this;
    }

    /**
     * @param array $config
     * @return static
     */
    public function setConfig(array $config)
    {
        $this->config = $config;

        return $this;
    }

    protected function bootLaravel(): Application
    {
        if ($this->app !== null) {
            return $this->app;
        }

        date_default_timezone_set('UTC');

        $app = new Application($this->basePath, $this->publicPath);

        if ($this->storagePath) {
            $app->useStoragePath($this->storagePath);
        }

        $app->instance('env', 'production');
        $app->instance('flarum.config', $this->config);
        $app->instance('config', $config = $this->getIlluminateConfig($app));

        $this->registerLogger($app);

        $this->registerCache($app);

        $app->register(DatabaseServiceProvider::class);
        $app->register(MigrationServiceProvider::class);
        $app->register(SettingsServiceProvider::class);
        $app->register(LocaleServiceProvider::class);
        $app->register(BusServiceProvider::class);
        $app->register(FilesystemServiceProvider::class);
        $app->register(HashServiceProvider::class);
        $app->register(MailServiceProvider::class);
        $app->register(ViewServiceProvider::class);
        $app->register(ValidationServiceProvider::class);

        $app->register(BusProvider::class);

        if ($app->isUpToDate()) {
            $settings = $app->make(SettingsRepositoryInterface::class);

            $config->set('mail.driver', $settings->get('mail_driver'));
            $config->set('mail.host', $settings->get('mail_host'));
            $config->set('mail.port', $settings->get('mail_port'));
            $config->set('mail.from.address', $settings->get('mail_from'));
            $config->set('mail.from.name', $settings->get('forum_title'));
            $config->set('mail.encryption', $settings->get('mail_encryption'));
            $config->set('mail.username', $settings->get('mail_username'));
            $config->set('mail.password', $settings->get('mail_password'));

            $app->register(DiscussionServiceProvider::class);
            $app->register(FormatterServiceProvider::class);
            $app->register(GroupServiceProvider::class);
            $app->register(NotificationServiceProvider::class);
            $app->register(PostServiceProvider::class);
            $app->register(SearchServiceProvider::class);
            $app->register(UserServiceProvider::class);

            $app->register(ApiServiceProvider::class);
            $app->register(ForumServiceProvider::class);
            $app->register(AdminServiceProvider::class);

            foreach ($this->extenders as $extender) {
                $app->call($extender);
            }

            $app->register(ExtensionServiceProvider::class);
            $app->boot();
        }

        $this->app = $app;

        return $app;
    }

    /**
     * @param Application $app
     * @return ConfigRepository
     */
    protected function getIlluminateConfig(Application $app)
    {
        return new ConfigRepository([
            'view' => [
                'paths' => [],
                'compiled' => $app->storagePath().'/views',
            ],
            'mail' => [
                'driver' => 'mail',
            ],
            'filesystems' => [
                'default' => 'local',
                'cloud' => 's3',
                'disks' => [
                    'flarum-avatars' => [
                        'driver' => 'local',
                        'root'   => $app->publicPath().'/assets/avatars'
                    ]
                ]
            ],
            'session' => [
                'lifetime' => 120,
                'files' => $app->storagePath().'/sessions',
                'cookie' => 'session'
            ]
        ]);
    }

    protected function registerLogger(Application $app)
    {
        $logger = new Logger($app->environment());
        $logPath = $app->storagePath().'/logs/flarum.log';

        $handler = new StreamHandler($logPath, Logger::DEBUG);
        $handler->setFormatter(new LineFormatter(null, null, true, true));

        $logger->pushHandler($handler);

        $app->instance('log', $logger);
        $app->alias('log', LoggerInterface::class);
    }

    protected function registerCache(Application $app)
    {
        $app->singleton('cache.store', function ($app) {
            return new CacheRepository($app->make('cache.filestore'));
        });

        $app->singleton('cache.filestore', function ($app) {
            return new FileStore(
                new Filesystem, $app->storagePath().'/cache'
            );
        });

        $app->alias('cache.filestore', Store::class);
        $app->alias('cache.store', Repository::class);
    }
}

<?php

namespace Flute\Modules\BrowserPush\Providers;

use Flute\Core\Support\ModuleServiceProvider;
use Flute\Modules\BrowserPush\Services\BrowserPushService;

class BrowserPushProvider extends ModuleServiceProvider
{
    public array $extensions = [];

    public function boot(\DI\Container $container): void
    {
        $this->bootstrapModule();

        $this->loadRoutes();
        $this->loadScss('Resources/assets/sass/push-banner.scss');
        $this->loadViews('Resources/views', 'browser-push');

        if (!is_admin_path() && is_installed() && user()->isLoggedIn()) {
            template()->prependTemplateToSection('footer', 'browser-push::push-banner');
        }
    }

    public function register(\DI\Container $container): void
    {
        $container->set(BrowserPushService::class, \DI\create(BrowserPushService::class));
        $container->set('push.service', \DI\get(BrowserPushService::class));
    }
}

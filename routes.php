<?php

use Flute\Core\Router\Contracts\RouterInterface;
use Flute\Modules\BrowserPush\Controllers\PushController;

router()->group([
    'prefix' => 'api/push',
    'middleware' => ['auth'],
], static function (RouterInterface $routeGroup) {
    $routeGroup->get('/vapid-key', [PushController::class, 'vapidKey']);
    $routeGroup->get('/status', [PushController::class, 'status']);
    $routeGroup->get('/pending', [PushController::class, 'pending']);
    $routeGroup->post('/subscribe', [PushController::class, 'subscribe']);
    $routeGroup->post('/unsubscribe', [PushController::class, 'unsubscribe']);
});

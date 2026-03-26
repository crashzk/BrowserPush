<?php

namespace Flute\Modules\BrowserPush\Controllers;

use Flute\Core\Support\BaseController;
use Flute\Core\Support\FluteRequest;
use Flute\Modules\BrowserPush\Services\BrowserPushService;
use Symfony\Component\HttpFoundation\JsonResponse;

class PushController extends BaseController
{
    public function vapidKey(): JsonResponse
    {
        $service = app(BrowserPushService::class);
        $key = $service->getVapidPublicKey();

        if (!$key) {
            return $this->error('VAPID keys unavailable', 500);
        }

        return $this->json(['publicKey' => $key]);
    }

    public function subscribe(FluteRequest $request): JsonResponse
    {
        $this->throttle('push.subscribe', 10, 60);

        $data = $request->input();

        $validation = $this->validate($data, [
            'endpoint' => 'required|string',
            'keys' => 'required|array',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        if ($validation !== true) {
            return $validation;
        }

        $endpoint = $data['endpoint'];

        if (!BrowserPushService::isAllowedEndpoint($endpoint)) {
            return $this->error('Invalid push endpoint', 400);
        }

        if (mb_strlen($endpoint) > 2048) {
            return $this->error('Endpoint too long', 400);
        }

        $keys = $data['keys'];

        if (mb_strlen($keys['p256dh'] ?? '') > 512 || mb_strlen($keys['auth'] ?? '') > 512) {
            return $this->error('Invalid key length', 400);
        }

        $service = app(BrowserPushService::class);

        $count = $service->countUserSubscriptions(user()->getCurrentUser());
        if ($count >= BrowserPushService::MAX_SUBSCRIPTIONS_PER_USER) {
            $service->removeOldestSubscription(user()->getCurrentUser());
        }

        $service->subscribe(
            user()->getCurrentUser(),
            $endpoint,
            $keys['p256dh'],
            $keys['auth'],
            $request->headers->get('User-Agent'),
        );

        return $this->success();
    }

    public function unsubscribe(FluteRequest $request): JsonResponse
    {
        $this->throttle('push.unsubscribe', 10, 60);

        $data = $request->input();

        $validation = $this->validate($data, [
            'endpoint' => 'required|string',
        ]);

        if ($validation !== true) {
            return $validation;
        }

        $service = app(BrowserPushService::class);
        $service->unsubscribe(user()->getCurrentUser(), $data['endpoint']);

        return $this->success();
    }

    public function status(): JsonResponse
    {
        $service = app(BrowserPushService::class);

        return $this->json([
            'subscribed' => $service->hasSubscription(user()->getCurrentUser()),
        ]);
    }

    public function pending(): JsonResponse
    {
        $service = app(BrowserPushService::class);
        $data = $service->getPendingNotification(user()->getCurrentUser());

        if (!$data) {
            return $this->json(['title' => null]);
        }

        return $this->json($data);
    }
}

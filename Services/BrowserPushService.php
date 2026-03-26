<?php

namespace Flute\Modules\BrowserPush\Services;

use Flute\Core\Database\Entities\User;
use Flute\Modules\BrowserPush\database\Entities\PushSubscription;
use Throwable;

class BrowserPushService
{
    public const MAX_SUBSCRIPTIONS_PER_USER = 5;

    /**
     * Allowed push service domains (SSRF protection).
     */
    private const ALLOWED_ENDPOINT_HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'push.services.mozilla.com',
        'notify.windows.com',
        'web.push.apple.com',
        'push.apple.com',
    ];

    private const VAPID_FILE = 'app/vapid_keys.json';

    protected ?array $vapidKeys = null;

    public static function isAllowedEndpoint(string $endpoint): bool
    {
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsed = parse_url($endpoint);

        if (($parsed['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = $parsed['host'] ?? '';

        foreach (self::ALLOWED_ENDPOINT_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    public function getVapidPublicKey(): ?string
    {
        return $this->getVapidKeys()['public'] ?? null;
    }

    public function subscribe(User $user, string $endpoint, ?string $p256dh, ?string $auth, ?string $userAgent = null): PushSubscription
    {
        $hash = hash('sha256', $endpoint);
        $existing = PushSubscription::findOne(['endpoint_hash' => $hash]);

        if ($existing) {
            $existing->user = $user;
            $existing->p256dh = $p256dh;
            $existing->auth = $auth;
            $existing->user_agent = mb_substr($userAgent ?? '', 0, 255) ?: null;
            $existing->save();

            return $existing;
        }

        $sub = new PushSubscription();
        $sub->user = $user;
        $sub->setEndpoint($endpoint);
        $sub->p256dh = $p256dh;
        $sub->auth = $auth;
        $sub->user_agent = mb_substr($userAgent ?? '', 0, 255) ?: null;
        $sub->save();

        return $sub;
    }

    public function unsubscribe(User $user, string $endpoint): bool
    {
        $hash = hash('sha256', $endpoint);
        $sub = PushSubscription::findOne([
            'endpoint_hash' => $hash,
            'user_id' => $user->id,
        ]);

        if ($sub) {
            $sub->delete();

            return true;
        }

        return false;
    }

    public function unsubscribeAll(User $user): void
    {
        $subs = PushSubscription::query()->where(['user_id' => $user->id])->fetchAll();

        foreach ($subs as $sub) {
            $sub->delete();
        }
    }

    public function hasSubscription(User $user): bool
    {
        return PushSubscription::findOne(['user_id' => $user->id]) !== null;
    }

    public function countUserSubscriptions(User $user): int
    {
        return count(
            PushSubscription::query()->where(['user_id' => $user->id])->fetchAll(),
        );
    }

    public function removeOldestSubscription(User $user): void
    {
        $oldest = PushSubscription::query()
            ->where(['user_id' => $user->id])
            ->orderBy('created_at', 'ASC')
            ->fetchOne();

        if ($oldest) {
            $oldest->delete();
        }
    }

    /**
     * @return PushSubscription[]
     */
    public function getUserSubscriptions(User $user): array
    {
        return PushSubscription::query()->where(['user_id' => $user->id])->fetchAll();
    }

    public function sendToUser(User $user, string $title, string $body, ?string $icon = null, ?string $url = null): void
    {
        $subscriptions = $this->getUserSubscriptions($user);

        if (empty($subscriptions)) {
            return;
        }

        $title = $this->sanitizeText($title, 200);
        $body = $this->sanitizeText($body, 500);

        $pushData = [
            'title' => $title,
            'body' => $body,
            'icon' => $icon,
            'url' => $url,
            'timestamp' => time(),
        ];

        try {
            cache()->set('push.pending.' . $user->id, $pushData, 120);
        } catch (Throwable) {
        }

        foreach ($subscriptions as $sub) {
            if (!self::isAllowedEndpoint($sub->endpoint)) {
                $sub->delete();

                continue;
            }

            try {
                $this->sendTickle($sub);
            } catch (Throwable $e) {
                if ($this->isExpiredSubscription($e)) {
                    $sub->delete();
                } else {
                    logs()->error('Push send failed: ' . $e->getMessage());
                }
            }
        }
    }

    public function getPendingNotification(User $user): ?array
    {
        try {
            $data = cache()->get('push.pending.' . $user->id);

            if (is_array($data) && isset($data['title'])) {
                cache()->delete('push.pending.' . $user->id);

                return $data;
            }
        } catch (Throwable) {
        }

        return null;
    }

    protected function sanitizeText(string $text, int $maxLength): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        return mb_substr($text, 0, $maxLength);
    }

    protected function sendTickle(PushSubscription $sub): void
    {
        $vapid = $this->getVapidKeys();

        if (!$vapid) {
            return;
        }

        $endpoint = $sub->endpoint;
        $parsed = parse_url($endpoint);
        $audience = $parsed['scheme'] . '://' . $parsed['host'];

        $header = json_encode(['typ' => 'JWT', 'alg' => 'ES256']);
        $jwtPayload = json_encode([
            'aud' => $audience,
            'exp' => time() + 43200,
            'sub' => 'mailto:admin@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        ]);

        $jwt = $this->createVapidJwt($header, $jwtPayload, $vapid['private']);

        if (!$jwt) {
            return;
        }

        $publicKeyBin = base64_decode(strtr($vapid['public'], '-_', '+/'));
        $encodedPublicKey = rtrim(strtr(base64_encode($publicKeyBin), '+/', '-_'), '=');

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_HTTPHEADER => [
                'Content-Length: 0',
                'Authorization: vapid t=' . $jwt . ', k=' . $encodedPublicKey,
                'TTL: 86400',
                'Urgency: normal',
            ],
        ]);

        curl_exec($ch);
        curl_close($ch);
    }

    protected function createVapidJwt(string $header, string $payload, string $privateKeyBase64): ?string
    {
        $headerEncoded = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');
        $payloadEncoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signingInput = $headerEncoded . '.' . $payloadEncoded;

        $privateKeyBin = base64_decode(strtr($privateKeyBase64, '-_', '+/'));

        if (strlen($privateKeyBin) !== 32) {
            return null;
        }

        $pem = $this->ecPrivateKeyToPem($privateKeyBin);

        if (!$pem) {
            return null;
        }

        $key = openssl_pkey_get_private($pem);

        if (!$key) {
            return null;
        }

        $result = openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

        if (!$result || !$signature) {
            return null;
        }

        $derSignature = $this->derToRaw($signature);
        $sigEncoded = rtrim(strtr(base64_encode($derSignature), '+/', '-_'), '=');

        return $signingInput . '.' . $sigEncoded;
    }

    protected function ecPrivateKeyToPem(string $privateKeyBin): ?string
    {
        // PKCS#8 DER encoding for EC private key on P-256
        $der = "\x30\x41\x02\x01\x00\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x04\x27\x30\x25\x02\x01\x01\x04\x20" . $privateKeyBin;

        return "-----BEGIN PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PRIVATE KEY-----\n";
    }

    protected function derToRaw(string $der): string
    {
        $pos = 0;
        if ($der[$pos++] !== "\x30") {
            return $der;
        }

        $pos++; // skip length

        if ($der[$pos++] !== "\x02") {
            return $der;
        }

        $rLen = ord($der[$pos++]);
        $r = substr($der, $pos, $rLen);
        $pos += $rLen;

        if ($der[$pos++] !== "\x02") {
            return $der;
        }

        $sLen = ord($der[$pos++]);
        $s = substr($der, $pos, $sLen);

        $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    protected function getVapidKeys(): ?array
    {
        if ($this->vapidKeys !== null) {
            return $this->vapidKeys;
        }

        $filePath = storage_path(self::VAPID_FILE);

        if (file_exists($filePath)) {
            $content = file_get_contents($filePath);
            $keys = json_decode($content, true);

            if (is_array($keys) && isset($keys['public'], $keys['private'])) {
                $this->vapidKeys = $keys;

                return $keys;
            }
        }

        $keys = $this->generateVapidKeys();

        if ($keys) {
            $dir = dirname($filePath);

            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }

            file_put_contents($filePath, json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            chmod($filePath, 0600);

            $this->vapidKeys = $keys;
        }

        return $keys;
    }

    protected function generateVapidKeys(): ?array
    {
        $config = [
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ];

        $opensslCnf = $this->findOpensslConfig();

        if ($opensslCnf) {
            $config['config'] = $opensslCnf;
        }

        $key = openssl_pkey_new($config);

        if (!$key) {
            logs()->error('BrowserPush: failed to generate VAPID keys: ' . openssl_error_string());

            return null;
        }

        $details = openssl_pkey_get_details($key);

        $publicKey = chr(4)
            . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        openssl_pkey_export($key, $pem, null, $opensslCnf ? ['config' => $opensslCnf] : []);
        $privDetails = openssl_pkey_get_details(openssl_pkey_get_private($pem));

        $publicKeyEncoded = rtrim(strtr(base64_encode($publicKey), '+/', '-_'), '=');
        $privateKeyEncoded = rtrim(strtr(base64_encode(str_pad($privDetails['ec']['d'], 32, "\0", STR_PAD_LEFT)), '+/', '-_'), '=');

        return [
            'public' => $publicKeyEncoded,
            'private' => $privateKeyEncoded,
        ];
    }

    protected function findOpensslConfig(): ?string
    {
        $candidates = [
            getenv('OPENSSL_CONF') ?: null,
            PHP_OS_FAMILY === 'Windows' ? dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf' : null,
            '/etc/ssl/openssl.cnf',
            '/etc/pki/tls/openssl.cnf',
            '/usr/local/etc/openssl/openssl.cnf',
        ];

        foreach ($candidates as $path) {
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    protected function isExpiredSubscription(Throwable $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'HTTP 404')
            || str_contains($msg, 'HTTP 410')
            || str_contains($msg, 'HTTP 401');
    }
}

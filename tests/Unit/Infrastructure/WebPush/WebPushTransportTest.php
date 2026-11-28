<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\WebPush;

use App\Infrastructure\WebPush\Aes128GcmWebPushEncoder;
use App\Infrastructure\WebPush\GuzzleWebPushTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

require_once dirname(__DIR__, 3).'/Support/WebPushKeys.php';
/**
 * Тесты кодировщика/транспорта WebPush: структура заголовков, тело
 * aes128gcm и классификация ответов push-сервиса.
 *
 * Расшифровка payload проверяется на стороне "подписчика" — тем же
 * алгоритмом, что делает браузер (ECDH + HKDF + AES-128-GCM).
 */
it('aes128gcm: тело расшифровывается на стороне подписчика', function (): void {
    $vapid = webPushTestEcKeyPair();
    $ua = webPushTestEcKeyPair();
    $authSecret = random_bytes(16);
    $payload = 'NewNotifications';

    $encoder = new Aes128GcmWebPushEncoder($vapid['public_raw'], $vapid['private_raw'], 'mailto:t@example.com');

    $out = $encoder->encode([
        'endpoint' => 'https://fcm.googleapis.com/wp/abc',
        'p256dh' => webPushTestB64Url($ua['public_raw']),
        'auth' => webPushTestB64Url($authSecret),
    ], $payload, 60, 'normal');

    $body = $out['body'];
    $salt = substr($body, 0, 16);

    expect(unpack('N', substr($body, 16, 4))[1])->toBe(4096)
        ->and(ord($body[20]))->toBe(65);

    $asPublic = substr($body, 21, 65);
    $cipher = substr($body, 21 + 65);

    // Расшифровка ровно так, как это делает браузер.
    $shared = openssl_pkey_derive(
        openssl_pkey_get_public(webPushTestPublicPem($asPublic)),
        webPushTestPrivatePem($ua['private_raw']),
    );
    $prk = hash_hmac('sha256', $shared, $authSecret, true);
    $ikm = hash_hmac('sha256', "WebPush: info\0".$ua['public_raw'].$asPublic, $prk, true);
    $saltPrk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', $saltPrk, "Content-Encoding: aes128gcm\0".pack('n', 1), true), 0, 16);
    $nonce = substr(hash_hmac('sha256', $saltPrk, "Content-Encoding: nonce\0".pack('n', 1), true), 0, 12);

    $tag = substr($cipher, -16);
    $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

    expect($plain)->toBe($payload."\x02")
        ->and($out['headers']['Content-Encoding'])->toBe('aes128gcm')
        ->and($out['headers']['Content-Type'])->toBe('application/octet-stream')
        ->and($out['headers']['Authorization'])->toStartWith('vapid t=')
        ->and($out['headers']['TTL'])->toBe('60');
});

it('aes128gcm: VAPID JWT подписан и содержит aud/exp/sub', function (): void {
    $vapid = webPushTestEcKeyPair();
    $ua = webPushTestEcKeyPair();

    $encoder = new Aes128GcmWebPushEncoder($vapid['public_raw'], $vapid['private_raw'], 'mailto:t@example.com');

    $out = $encoder->encode([
        'endpoint' => 'https://fcm.googleapis.com/wp/abc',
        'p256dh' => webPushTestB64Url($ua['public_raw']),
        'auth' => webPushTestB64Url(random_bytes(16)),
    ], 'NewNotifications', 60, 'normal');

    preg_match('/vapid t=([^,]+), k=(.+)$/', $out['headers']['Authorization'], $m);
    [$header, $claims, $signature] = explode('.', $m[1]);

    $verified = openssl_verify(
        $header.'.'.$claims,
        webPushTestB64UrlDecode($signature),
        webPushTestPublicPem(webPushTestB64UrlDecode($m[2])),
        OPENSSL_ALGO_SHA256,
    );

    $claimsData = json_decode(webPushTestB64UrlDecode($claims), true);

    expect($verified)->toBe(1)
        ->and($claimsData['aud'])->toBe('https://fcm.googleapis.com')
        ->and($claimsData['sub'])->toBe('mailto:t@example.com')
        ->and($claimsData['exp'])->toBeGreaterThan(time());
});

it('транспорт классифицирует ответы push-сервиса', function (int $status, bool $ok, bool $gone): void {
    $vapid = webPushTestEcKeyPair();
    $ua = webPushTestEcKeyPair();

    $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response($status)]))]);

    $transport = new GuzzleWebPushTransport(
        http: $client,
        encoder: new Aes128GcmWebPushEncoder($vapid['public_raw'], $vapid['private_raw'], 'mailto:t@example.com'),
        ttl: 60,
        urgency: 'normal',
    );

    $result = $transport->send([
        'endpoint' => 'https://fcm.googleapis.com/wp/abc',
        'p256dh' => webPushTestB64Url($ua['public_raw']),
        'auth' => webPushTestB64Url(random_bytes(16)),
    ], ['title' => 'NewNotifications']);

    expect($result->ok)->toBe($ok)
        ->and($result->gone)->toBe($gone);
})->with([
    '201 Created' => [201, true, false],
    '404 подписка удалена' => [404, false, true],
    '410 подписка удалена' => [410, false, true],
    '500 сбой сервиса' => [500, false, false],
]);

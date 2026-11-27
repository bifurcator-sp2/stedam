<?php

declare(strict_types=1);

namespace App\Infrastructure\WebPush;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * Кодировщик полезной нагрузки WebPush: AES-128-GCM (RFC 8188, схема
 * `aes128gcm`) + подпись VAPID (RFC 8292, ES256).
 *
 * Реализовано без сторонних библиотек — только OpenSSL и base64url, поэтому
 * не тянет Composer-пакеты. Кодировка строго `aes128gcm`: именно её
 * поддерживают Chrome, Firefox, Edge и Safari (16k+, macOS 13+).
 *
 * ВАЖНО: перед использованием основного канала в проде обязательно прогнать
 * реальную отправку в браузер (тесты проверяют только структуру заголовков —
 * расшифровку делают браузеры). См. docs/browser-push-spec.md.
 */
final readonly class Aes128GcmWebPushEncoder
{
    public function __construct(
        private string $vapidPublicKeyRaw,   // 65 байт: 0x04||X||Y
        private string $vapidPrivateKeyRaw,  // 32 байта (d)
        private string $vapidSubject,
    ) {}

    /**
     * Собрать тело и заголовки push-запроса.
     *
     * @param  array{endpoint: string, p256dh: string, auth: string}  $subscription
     * @return array{body: string, headers: array<string, string>}
     */
    public function encode(array $subscription, string $payload, int $ttl, string $urgency): array
    {
        $endpoint = $subscription['endpoint'];
        $audience = $this->audience($endpoint);

        $uaPublic = $this->b64urlDecode($subscription['p256dh']);   // 65 байт
        $authSecret = $this->b64urlDecode($subscription['auth']);   // 16 байт

        // 1. Эфемерная пара для ECDH (as-public 'ua').
        $asKey = $this->generateEcKey();

        // 2. Общий секрет ECDH(капризный, PRIVATE).
        $shared = $this->deriveSharedSecret($subscription['p256dh'], $asKey['private']);

        // 3. Соль (16 байт) + ключи из HKDF.
        $salt = random_bytes(16);
        $prk = hash_hmac('sha256', $shared, $authSecret, true);
        $ikm = hash_hmac('sha256', "WebPush: info\0".$uaPublic.$asKey['public'], $prk, true);

        // Псевдо-Random Key с учётом соли (RFC 8188 §2.1, hmac-sha256).
        $saltPrk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', $saltPrk, "Content-Encoding: aes128gcm\0".pack('n', 0x0001), true), 0, 16);
        $nonce = substr(hash_hmac('sha256', $saltPrk, "Content-Encoding: nonce\0".pack('n', 0x0001), true), 0, 12);

        // 4. Шифрование AES-128-GCM.
        //    RFC 8188: plaintext = payload || 0x02 (padding delimiter),
        //    дополнение нулями по желанию (не делаем — минимальный размер).
        $plain = $payload."\x02";
        $tag = '';
        $cipherText = openssl_encrypt(
            $plain,
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16,
        );

        if ($cipherText === false) {
            throw new \RuntimeException('WebPush: AES-128-GCM encryption failed');
        }

        $record = $this->buildAes128gcmBody($salt, $asKey['public'], $cipherText.$tag);

        return [
            'body' => $record,
            'headers' => [
                'Content-Type' => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'TTL' => (string) $ttl,
                'Urgency' => $urgency,
                'Authorization' => 'vapid t='.$this->vapidJwt($audience).', k='.$this->b64url($this->vapidPublicKeyRaw),
            ],
        ];
    }

    /**
     * Сборка тела сообщения формата aes128gcm:
     * salt(16) | rs(4, big-endian) | idlen(1) | keyid(as-public 65 байт) | ciphertext.
     */
    private function buildAes128gcmBody(string $salt, string $asPublic, string $cipherText): string
    {
        // rs — размер записи; должен быть >= размеру блока plaintext (4096),
        // тут вся нагрузка — одна запись, берём 4096.
        $rs = 4096;

        return $salt
            .pack('N', $rs)
            .chr(strlen($asPublic))
            .$asPublic
            .$cipherText;
    }

    /**
     * VAPID JWT (ES256, RFC 8292): {aud, exp, sub}.
     */
    private function vapidJwt(string $audience): string
    {
        $header = $this->b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $claims = $this->b64url(json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => $this->vapidSubject,
        ], JSON_THROW_ON_ERROR));

        $signingInput = $header.'.'.$claims;
        $signature = '';
        $signOk = openssl_sign($signingInput, $signature, $this->pemPrivateKey(), OPENSSL_ALGO_SHA256);

        if (! $signOk) {
            throw new \RuntimeException('WebPush: VAPID JWT signing failed');
        }

        return $signingInput.'.'.$this->b64url($signature);
    }

    private function audience(string $endpoint): string
    {
        $parts = parse_url($endpoint);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('WebPush: malformed subscription endpoint');
        }

        $audience = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $audience .= ':'.$parts['port'];
        }

        return $audience;
    }

    /**
     * @return array{public: string, private: string}
     */
    private function generateEcKey(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false) {
            throw new \RuntimeException('WebPush: cannot generate EC key');
        }

        $details = openssl_pkey_get_details($key);

        return [
            'public' => "\x04".$details['ec']['x'].$details['ec']['y'], // uncompressed point
            'private' => $details['ec']['d'],
        ];
    }

    /**
     * ECDH: общий секрет 32 байта (X-координата) между нашей приватной парой
     * и публичным ключом подписчика.
     */
    private function deriveSharedSecret(string $peerPublicB64, string $ourPrivateRaw): string
    {
        $peerPublic = $this->b64urlDecode($peerPublicB64);

        // Детерминированный PEM для приватного ключа из сырых 32 байт.
        $privatePem = $this->pemEcPrivateKey($ourPrivateRaw);

        $peerKey = openssl_pkey_get_public($this->pemEcPublicKeyFromRaw($peerPublic));

        $secret = openssl_pkey_derive($peerKey, $privatePem);

        if ($secret === false) {
            throw new \RuntimeException('WebPush: ECDH key derivation failed');
        }

        return $secret;
    }

    private function pemPrivateKey(): string
    {
        return $this->pemEcPrivateKey($this->vapidPrivateKeyRaw);
    }

    /**
     * Обернуть 32-байтовое значение в PEM-эквивалент EC PRIVATE KEY
     * (SEC1 + X9.62 namedCurve prime256v1).
     */
    private function pemEcPrivateKey(string $d): string
    {
        // ASN.1: ECPrivateKey ::= SEQUENCE { version, privateKey OCTET STRING,
        // [0] parameters OBJECT IDENTIFIER prime256v1 }
        $oid = hex2bin('06082a8648ce3d030107'); // OID 1.2.840.10045.3.1.7
        $version = hex2bin('020101');           // INTEGER 1
        $octet = "\x04".chr(strlen($d)).$d;     // OCTET STRING
        $params = "\xa0".chr(strlen($oid)).$oid; // [0] {...}

        $body = $version.$octet.$params;
        $der = "\x30".$this->derLength(strlen($body)).$body;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /**
     * Обернуть 65-байтовый uncompressed point в PEM SubjectPublicKeyInfo.
     */
    private function pemEcPublicKeyFromRaw(string $uncompressedPoint): string
    {
        $algId = hex2bin('301306072a8648ce3d020106082a8648ce3d030107'); // id-ecPublicKey + prime256v1
        $bitString = "\x03".chr(strlen($uncompressedPoint) + 1)."\x00".$uncompressedPoint;
        $body = $algId.$bitString;
        $der = "\x30".$this->derLength(strlen($body)).$body;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $data): string
    {
        $padded = str_pad(strtr($data, '-_', '+/'), (int) (ceil(strlen($data) / 4) * 4), '=');

        $decoded = base64_decode($padded, true);

        if ($decoded === false) {
            throw new \InvalidArgumentException('WebPush: invalid base64url value');
        }

        return $decoded;
    }
}

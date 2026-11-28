<?php

/**
 * Помощники тестов WebPush (ECDH/P-256 в чистом PHP).
 *
 * Нужны только тестам: чтобы расшифровать/проверить то, что сгенерировал
 * Aes128GcmWebPushEncoder, тем же алгоритмом, что делает браузер.
 */

if (! function_exists('webPushTestEcKeyPair')) {
    /**
     * @return array{public_raw: string, private_raw: string}
     */
    function webPushTestEcKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = openssl_pkey_get_details($key);

        return [
            'public_raw' => "\x04".$d['ec']['x'].$d['ec']['y'],
            'private_raw' => $d['ec']['d'],
        ];
    }
}

if (! function_exists('webPushTestB64Url')) {
    function webPushTestB64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (! function_exists('webPushTestB64UrlDecode')) {
    function webPushTestB64UrlDecode(string $data): string
    {
        return (string) base64_decode(
            strtr($data, '-_', '+/').str_repeat('=', (4 - strlen($data) % 4) % 4),
            true,
        );
    }
}

if (! function_exists('webPushTestDerLength')) {
    function webPushTestDerLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}

if (! function_exists('webPushTestPrivatePem')) {
    /**
     * SEC1 EC PRIVATE KEY из 32-байтового приватного значения.
     */
    function webPushTestPrivatePem(string $d): string
    {
        $oid = hex2bin('06082a8648ce3d030107');
        $body = hex2bin('020101')."\x04".chr(strlen($d)).$d."\xa0".chr(strlen($oid)).$oid;
        $der = "\x30".webPushTestDerLength(strlen($body)).$body;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }
}

if (! function_exists('webPushTestPublicPem')) {
    /**
     * SubjectPublicKeyInfo из 65-байтового uncompressed point.
     */
    function webPushTestPublicPem(string $point): string
    {
        $alg = hex2bin('301306072a8648ce3d020106082a8648ce3d030107');
        $body = $alg."\x03".chr(strlen($point) + 1)."\x00".$point;
        $der = "\x30".webPushTestDerLength(strlen($body)).$body;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }
}

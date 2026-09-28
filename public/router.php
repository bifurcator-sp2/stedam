<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;

// === Статика: отдаём напрямую с правильным MIME ===
if ($path !== '/' && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    $mimes = [
        'js'    => 'application/javascript',
        'mjs'   => 'application/javascript',
        'css'   => 'text/css',
        'json'  => 'application/json',
        'svg'   => 'image/svg+xml',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'wasm'  => 'application/wasm',
    ];

    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
    }

    // Кэширование для хэшированных файлов Nuxt
    if (str_starts_with($path, '/app/_nuxt/')) {
        header('Cache-Control: public, max-age=31536000, immutable');
    }

    return false; // PHP-сервер отдаст файл сам
}

// === Фикс для Laravel: считаем, что скрипт — index.php ===
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require_once __DIR__ . '/index.php';

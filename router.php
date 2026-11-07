<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (str_starts_with($path, '/Files/') || str_starts_with($path, '/data/')) {
    http_response_code(404);
    exit('Not found');
}

if ($path === '/login' || $path === '/login/') {
    define('SECRET_LOGIN_ACCESS', true);
    require __DIR__ . '/admin.php';
    exit;
}

if (str_starts_with($path, '/dokumen/')) {
    $_GET['slug'] = rawurldecode(substr($path, strlen('/dokumen/')));
    require __DIR__ . '/document.php';
    exit;
}

if (str_starts_with($path, '/folder/')) {
    $_GET['folder'] = rawurldecode(substr($path, strlen('/folder/')));
    require __DIR__ . '/index.php';
    exit;
}

$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';

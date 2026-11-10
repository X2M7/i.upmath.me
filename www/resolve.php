<?php

declare(strict_types=1);

use S2\Tex\Cache\CacheProvider;
use S2\Tex\Processor\Request;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

$error = 'Invalid formula';

// Decode bounded input in the lightweight pool, then let nginx serve cached files.
try {
    $request = Request::createFromUri($_SERVER['REQUEST_URI']);
} catch (\InvalidArgumentException | \RuntimeException $e) {
    http_response_code(400);
    header('Cache-Control: no-store');
    require __DIR__ . '/400.php';
    exit;
}

$cacheProvider = new CacheProvider(CACHE_SUCCESS_DIR, CACHE_FAIL_DIR);
if (is_file($cacheProvider->cachePathFromRequest($request, true))) {
    http_response_code(400);
    header('Cache-Control: no-store');
    require __DIR__ . '/400.php';
    exit;
}

// The path is derived from a hash; no user-supplied path enters the redirect.
$cachePath = $cacheProvider->cachePathFromRequest($request, false);
$relativePath = substr($cachePath, strlen(CACHE_SUCCESS_DIR));
$contentTypes = [Request::SVG => 'image/svg+xml', Request::PNG => 'image/png', Request::JPG => 'image/jpeg'];
header('Content-Type: ' . $contentTypes[$request->getExtension()]);
header('X-Accel-Redirect: /_cache/' . $relativePath .
    (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));

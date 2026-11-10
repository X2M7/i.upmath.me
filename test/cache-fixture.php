<?php

declare(strict_types=1);

use S2\Tex\Cache\CacheProvider;
use S2\Tex\Helper;
use S2\Tex\Processor\Request;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';

$provider = new CacheProvider(CACHE_SUCCESS_DIR, CACHE_FAIL_DIR);
$request = new Request('cache-smoke-legacy-gzip', Request::SVG);
$path = $provider->cachePathFromRequest($request, false);
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"><rect width="20" height="20"/></svg>';
Helper::filePut($path . '.gz', gzencode($svg), true);
touch($path . '.gz', 1700000000);
if (is_file($path)) {
    unlink($path);
}

Helper::filePut($provider->cachePathFromRequest(new Request('cache-smoke-invalid', Request::SVG), true), 'Invalid formula');
echo "Seeded gzip-only and invalid-formula fixtures\n";

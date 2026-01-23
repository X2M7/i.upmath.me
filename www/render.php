<?php
/**
* Entry point for rendering.
*
* @copyright 2014-2020 Roman Parpalak
* @license   http://www.opensource.org/licenses/mit-license.php MIT
* @package   Upmath Latex Renderer
* @link      https://i.upmath.me
*/
use hollodotme\FastCGI\Client;
use hollodotme\FastCGI\Requests\PostRequest;
use hollodotme\FastCGI\SocketConnections\UnixDomainSocket;
use Katzgrau\KLogger\Logger;
use S2\Tex\Cache\CacheProvider;
use S2\Tex\Processor\CachedResponse;
use S2\Tex\Processor\PostProcessor;
use S2\Tex\Processor\Processor;
use S2\Tex\Processor\Request;
use S2\Tex\Renderer\JpgConverter;
use S2\Tex\Renderer\PngConverter;
use S2\Tex\Renderer\Renderer;
use S2\Tex\Templater;

require '../vendor/autoload.php';
require '../config.php';

$isDebug = defined('DEBUG') && DEBUG;
error_reporting($isDebug ? E_ALL : -1);

// Setting up external commands
define('LATEX_COMMAND', TEX_PATH . 'latex -output-directory=' . TMP_DIR);
define('DVISVG_COMMAND', TEX_PATH . 'dvisvgm %1$s -o %1$s.svg -n --exact -v0 --relative --zoom=' . OUTER_SCALE);
// define('DVIPNG_COMMAND', TEX_PATH . 'dvipng -T tight %1$s -o %1$s.png -D ' . (96 * OUTER_SCALE)); // outdated
define('SVG2PNG_COMMAND', 'rsvg-convert %1$s -d 192 -p 192 -b white'); // stdout
define('SVG2JPG_COMMAND', 'rsvg-convert %1$s -d 192 -p 192 -b white -f png | convert png:- -quality 98 jpg:-'); // stdout

function error400($error = 'Invalid formula')
{
    header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
    include '400.php';
}

//ignore_user_abort();
ini_set('max_execution_time', 10);
header('X-Powered-By: Upmath Latex Renderer');

$templater = new Templater(TPL_DIR);
$pngConverter = new PngConverter(SVG2PNG_COMMAND);
$jpgConverter = new JpgConverter(SVG2JPG_COMMAND);
$renderer     = new Renderer($templater, TMP_DIR, TEX_PATH, LATEX_COMMAND, DVISVG_COMMAND);
$renderer
    ->setPngConverter($pngConverter)
    ->setJpgConverter($jpgConverter)
    ->setIsDebug($isDebug);

if (defined('LOG_DIR')) {
    $renderer->setLogger(new Logger(LOG_DIR));
}

$cacheProvider = new CacheProvider(CACHE_SUCCESS_DIR, CACHE_FAIL_DIR);
$processor     = new Processor($renderer, $cacheProvider, $pngConverter, $jpgConverter);

try {
    // IMPORTANT: use full REQUEST_URI so Request can see query (?c=...)
    $request = Request::createFromUri($_SERVER['REQUEST_URI']);
} catch (Exception $e) {
    error400($isDebug ? $e->getMessage() : 'Invalid formula');
    die;
}

$response = $processor->process($request);

if (!$response->hasError()) {
    $response->echoContent();
} else {
    error400($isDebug ? $response->getError() : 'Invalid formula');
}

if (!$isDebug && !($response instanceof CachedResponse)) {
    // Disconnecting from web-server
    flush();
    fastcgi_finish_request();

    $postProc     = new PostProcessor($cacheProvider);
    $asyncRequest = $postProc->cacheResponseAndGetAsyncRequest($response, $_SERVER['HTTP_REFERER'] ?? 'no referer');

    if ($asyncRequest !== null) {
        $connection = new UnixDomainSocket(FASTCGI_SOCKET, 1000, 1000);
        $client     = new Client();

        // IMPORTANT: pass color param to async processor too
        $content = http_build_query([
            'formula'    => $asyncRequest->getFormula(),
            'extension'  => $asyncRequest->getExtension(),
            'c'          => $_GET['c'] ?? ($_GET['color'] ?? ''),
        ]);

        $request = new PostRequest(realpath('../cache_processor.php'), $content);
        $client->sendAsyncRequest($connection, $request);
    }
}

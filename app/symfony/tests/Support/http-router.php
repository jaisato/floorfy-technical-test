<?php

/*
 * Router for PHP's built-in server, started by LocalHttpServer.
 *
 * The download tests need a real HTTP peer - redirects, headers, statuses and
 * bodies are exactly what is under test - and must never reach the internet.
 * This answers on 127.0.0.1 and is the only host the tests are allowed to talk
 * to (see LoopbackTargetPolicy).
 */

declare(strict_types=1);

/** The smallest valid PNG: a single transparent pixel. */
const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($requestUri) ? $requestUri : '/', \PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$host = is_string($host) ? $host : '127.0.0.1';

$png = (string) base64_decode(ONE_PIXEL_PNG, true);

$redirect = static function (string $location, int $status = 302): void {
    header('Location: '.$location, true, $status);
};

switch ($path) {
    case '/image.png':
        header('Content-Type: image/png');
        echo $png;
        break;

    case '/redirect/image.png':
        // Where "Location: image.png" sent from /redirect/relative has to land.
        header('Content-Type: image/png');
        echo $png;
        break;

    case '/image-octet-stream':
        // What object stores serve when nobody set a type. The body decides.
        header('Content-Type: application/octet-stream');
        echo $png;
        break;

    case '/octet-stream-not-an-image':
        header('Content-Type: application/octet-stream');
        echo '<html><body>definitely not a picture</body></html>';
        break;

    case '/not-an-image':
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><body>definitely not a picture</body></html>';
        break;

    case '/image-typed-but-html-body':
        // A server claiming image/png while sending something else: what was
        // written is what gets handed to ffmpeg, so the body has the last word.
        header('Content-Type: image/png');
        echo '<html><body>definitely not a picture</body></html>';
        break;

    case '/declared-too-large':
        header('Content-Type: image/png');
        header('Content-Length: 104857600');
        echo $png;
        break;

    case '/oversized':
        header('Content-Type: image/png');
        // No Content-Length: the streaming cap is what has to stop this one.
        echo str_repeat('x', 64 * 1024);
        break;

    case '/empty':
        header('Content-Type: image/png');
        http_response_code(204);
        break;

    case '/redirect/absolute':
        $redirect('http://'.$host.'/image.png');
        break;

    case '/redirect/relative':
        $redirect('image.png');
        break;

    case '/redirect/root-relative':
        $redirect('/image.png');
        break;

    case '/nested/redirect/parent':
        $redirect('../../image.png');
        break;

    case '/redirect/to-private':
        // The reason every hop is re-checked instead of letting the HTTP client
        // follow redirects: this is cloud instance metadata.
        $redirect('http://169.254.169.254/latest/meta-data/');
        break;

    case '/redirect/loop':
        $redirect('/redirect/loop');
        break;

    case '/redirect/no-location':
        http_response_code(302);
        break;

    case '/server-error':
        http_response_code(500);
        echo 'boom';
        break;

    default:
        http_response_code(404);
        echo 'not found';
}

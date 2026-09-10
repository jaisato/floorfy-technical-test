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

    case '/redirect/protocol-relative':
        // "//host/path" names a different host, not a path on this one.
        $redirect('//'.$host.'/image.png');
        break;

    case '/redirect/with-fragment':
        // The fragment is never sent in a request, and must not be mistaken for
        // part of the path.
        $redirect('image.png#somewhere');
        break;

    case '/redirect/query-only':
        // "?x=2" keeps the current path and replaces the query.
        if (($_GET['x'] ?? null) === '2') {
            header('Content-Type: image/png');
            echo $png;
            break;
        }

        $redirect('?x=2');
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
        // Two URLs pointing at each other: a redirect straight back to the
        // same URL is refused on the spot (see /redirect/self), so a loop that
        // has to be caught by the hop limit needs at least two hops.
        $redirect('/redirect/loop-back');
        break;

    case '/redirect/loop-back':
        $redirect('/redirect/loop');
        break;

    case '/redirect/self':
        $redirect('/redirect/self');
        break;

    case '/redirect/no-location':
        http_response_code(302);
        break;

    case '/redirect/empty-location':
        $redirect('   ');
        break;

    case '/redirect/not-modified':
        // 304 never carries a Location: it is not a redirect for a GET.
        http_response_code(304);
        break;

    case '/server-error':
        http_response_code(500);
        echo 'boom';
        break;

        // ---- webhook endpoints, for the callback delivery tests -----------------

    case '/hook/record':
        // Writes what arrived to a file the test names, so the test can check
        // the headers and the body a real receiver would see.
        $id = preg_replace('/[^a-z0-9]/', '', (string) ($_GET['id'] ?? ''));
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        file_put_contents(sys_get_temp_dir().'/floorfy-hook-'.$id.'.json', json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? '',
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
            'headers' => $headers,
            'body' => file_get_contents('php://input'),
        ], \JSON_THROW_ON_ERROR));
        http_response_code(204);
        break;

    case '/hook/fail':
        http_response_code(503);
        echo 'try later';
        break;

    case '/hook/redirect':
        // A receiver that redirects is not followed: the redirect target could
        // be anything, including an address the guard would refuse.
        $redirect('/hook/record?id=redirected', 307);
        break;

    case '/hook/slow':
        sleep(3);
        http_response_code(204);
        break;

    default:
        http_response_code(404);
        echo 'not found';
}

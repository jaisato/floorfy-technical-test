<?php

declare(strict_types=1);

namespace App\Ui\Http\Controller;

use App\Task\Application\Url\VideoUrls;
use App\Ui\Http\Response\ApiProblem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves a rendered video, after checking the signature its URL carries.
 *
 * The alternative was nginx's secure_link, which needs no PHP at all. This
 * won because the check is then part of the application - covered by the suite,
 * identical on every deployment, and able to answer in problem+json like the
 * rest of the API - while the cost it would otherwise carry, an FPM worker held
 * for the length of a download, is avoided by handing the transfer back to
 * nginx: with VIDEOS_X_ACCEL_PREFIX set the answer is a header, and nginx
 * streams the file from a location the outside world cannot address. Without
 * it - the test suite, `symfony server` - PHP sends the file itself.
 *
 * The route only admits the two shapes this application produces, so no request
 * can name a path of its own choosing.
 */
#[AsController]
final readonly class VideoController
{
    private const string NAME_PATTERN = '(?:partial|final)_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.mp4';

    public function __construct(
        private VideoUrls $urls,
        #[Autowire(param: 'app.videos_dir')]
        private string $videosDir,
        #[Autowire(env: 'VIDEOS_X_ACCEL_PREFIX')]
        private string $xAccelPrefix,
    ) {
    }

    #[Route(
        '/videos/{name}',
        name: 'videos_serve',
        requirements: ['name' => self::NAME_PATTERN],
        methods: ['GET', 'HEAD'],
    )]
    public function serve(string $name, Request $request): Response
    {
        if (!$this->urls->mayServe(VideoUrls::PREFIX.$name, $request->query->getString('expires') ?: null, $request->query->getString('sig') ?: null)) {
            // 403, not 404: the video may well exist, and pretending otherwise
            // would have a client with a stale link hunting for a lost task.
            return ApiProblem::response(Response::HTTP_FORBIDDEN, 'El enlace del vídeo no es válido o ha caducado.');
        }

        $file = $this->videosDir.'/'.$name;

        if (!is_file($file)) {
            return ApiProblem::response(Response::HTTP_NOT_FOUND, 'No hay ningún vídeo con ese nombre.');
        }

        $response = '' === $this->xAccelPrefix
            ? new BinaryFileResponse($file)
            : new Response('', Response::HTTP_OK, ['X-Accel-Redirect' => rtrim($this->xAccelPrefix, '/').'/'.$name]);

        // Set here rather than sniffed: BinaryFileResponse would need the Mime
        // component to guess it, and this application produces exactly one kind
        // of file.
        $response->headers->set('Content-Type', 'video/mp4');

        // The bytes never change - the name carries the id - but a signed URL
        // stops working, so a shared cache must not keep serving it afterwards.
        $response->headers->set(
            'Cache-Control',
            $this->urls->signingEnabled() ? 'private, max-age=300' : 'public, max-age=2592000, immutable',
        );

        return $response;
    }
}

<?php

declare(strict_types=1);

namespace App\Ui\Http\Controller;

use App\Task\Application\Command\CancelVideoTaskCommand;
use App\Task\Application\Command\CreateVideoTaskCommand;
use App\Task\Application\Command\RetryVideoTaskCommand;
use App\Task\Application\DTO\VideoTaskView;
use App\Task\Application\Query\GetVideoTaskQuery;
use App\Task\Application\ReadModel\TaskPage;
use App\Ui\Http\Request\CreateTaskRequest;
use App\Ui\Http\Request\ListTasksRequest;
use App\Ui\Http\Response\ApiProblem;
use App\Ui\Http\Response\PageResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsController]
#[Route('/api/tasks')]
#[OA\Tag(name: 'Tareas')]
// Which credentials the API accepts is declared once, for the whole document,
// in nelmio_api_doc.yaml: both schemes, plus "none", because with API_TOKENS
// empty the API is open. The 401 belongs on every operation, though.
#[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
final readonly class TaskController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessageBusInterface $queryBus,
        private ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_tasks_create', methods: ['POST'])]
    #[OA\Post(
        summary: 'Crea una tarea de vídeo',
        description: 'Escribe la tarea y sus partes en una transacción y encola el trabajo. El vídeo se genera en segundo plano.',
    )]
    #[OA\Parameter(ref: '#/components/parameters/idempotencyKey')]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['images'],
            properties: [
                new OA\Property(
                    property: 'images',
                    description: 'De 1 a 20 imágenes, en el orden en que se concatenan.',
                    type: 'array',
                    items: new OA\Items(
                        required: ['url', 'transition'],
                        properties: [
                            new OA\Property(property: 'url', type: 'string', maxLength: 2048, example: 'https://example.com/a.jpg'),
                            new OA\Property(property: 'transition', type: 'string', enum: ['pan', 'zoom_in', 'zoom_out']),
                            new OA\Property(property: 'duration', type: 'number', format: 'float', maximum: 15, minimum: 1),
                        ],
                        type: 'object',
                    ),
                    maxItems: 20,
                    minItems: 1,
                ),
                new OA\Property(property: 'callback_url', description: 'Se notifica el desenlace con un POST firmado. Pasa por la misma guardia SSRF que las imágenes.', type: 'string', nullable: true),
                new OA\Property(property: 'options', ref: '#/components/schemas/RenderOptions'),
            ],
            type: 'object',
        ),
    )]
    #[OA\Response(
        response: 201,
        description: 'Tarea creada. Con Idempotency-Key repetida, la respuesta original y la cabecera Idempotency-Replayed: true.',
        content: new OA\JsonContent(
            required: ['task_id', 'status'],
            properties: [
                new OA\Property(property: 'task_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'status', type: 'string', example: 'pending'),
            ],
            type: 'object',
        ),
    )]
    #[OA\Response(response: 400, ref: '#/components/responses/BadRequest')]
    #[OA\Response(response: 409, description: 'Una petición con esa Idempotency-Key sigue en curso.', content: new OA\JsonContent(ref: '#/components/schemas/Problem'))]
    #[OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntity')]
    #[OA\Response(response: 429, ref: '#/components/responses/TooManyRequests')]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!\is_array($payload)) {
            return ApiProblem::response(Response::HTTP_BAD_REQUEST, 'El cuerpo de la petición no es un objeto JSON válido.');
        }

        $dto = CreateTaskRequest::fromArray($payload);
        $violations = $this->validator->validate($dto);

        if (\count($violations) > 0) {
            return self::validationFailed($violations, 'images');
        }

        // The command carries what was validated, not the body that arrived:
        // persisting the raw payload stored unvalidated extra fields and left
        // the stored images out of step with the ones actually queued.
        $envelope = $this->commandBus->dispatch(new CreateVideoTaskCommand($dto->toImageList(), $dto->callbackUrl(), $dto->optionOverrides()));
        $taskId = $envelope->last(HandledStamp::class)?->getResult();

        if (!\is_string($taskId)) {
            // A 201 whose body says "task_id": null is worse than an error: the
            // client has nothing to poll and no reason to retry.
            return ApiProblem::response(Response::HTTP_INTERNAL_SERVER_ERROR, 'No se pudo crear la tarea.');
        }

        return new JsonResponse(['task_id' => $taskId, 'status' => 'pending'], Response::HTTP_CREATED);
    }

    #[Route('', name: 'api_tasks_list', methods: ['GET'])]
    #[OA\Get(
        summary: 'Lista tareas',
        description: 'De la más reciente a la más antigua. La cabecera Link (RFC 8288) lleva las páginas vecinas con todos los filtros de la petición.',
    )]
    #[OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'processing', 'completed', 'failed', 'canceled']))]
    #[OA\Parameter(name: 'createdFrom', description: 'Fecha ISO 8601; una fecha sola es el inicio de ese día, en UTC.', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'createdTo', in: 'query', schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1, minimum: 1))]
    #[OA\Parameter(name: 'limit', description: 'Un valor mayor que el máximo se recorta al máximo.', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100, minimum: 1))]
    #[OA\Response(response: 200, description: 'Una página de tareas.', content: new OA\JsonContent(ref: '#/components/schemas/TaskPage'))]
    #[OA\Response(response: 400, ref: '#/components/responses/BadRequest')]
    public function list(Request $request): JsonResponse
    {
        $dto = ListTasksRequest::fromQuery($request->query->all());
        $violations = $this->validator->validate($dto);

        if (\count($violations) > 0) {
            return self::validationFailed($violations, 'query');
        }

        $page = $this->queryBus->dispatch($dto->toQuery())->last(HandledStamp::class)?->getResult();

        if (!$page instanceof TaskPage) {
            return ApiProblem::response(Response::HTTP_INTERNAL_SERVER_ERROR, 'No se pudo obtener el listado de tareas.');
        }

        return PageResponse::serve($request, $page);
    }

    #[Route('/{id}', name: 'api_tasks_get', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[OA\Get(summary: 'Estado y progreso de una tarea', description: 'Incluye cada parte con su URL o su error.')]
    #[OA\Parameter(ref: '#/components/parameters/taskId')]
    #[OA\Response(response: 200, description: 'La tarea y sus partes.', content: new OA\JsonContent(ref: '#/components/schemas/Task'))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function get(string $id): JsonResponse
    {
        $view = $this->view($id);

        if (null === $view) {
            return self::notFound();
        }

        return new JsonResponse($view->toArray());
    }

    #[Route('/{id}/final', name: 'api_tasks_final', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    #[OA\Get(summary: 'URL del vídeo final', description: 'null mientras la tarea no ha terminado. Con VIDEO_URL_SECRET configurado la URL va firmada y caduca.')]
    #[OA\Parameter(ref: '#/components/parameters/taskId')]
    #[OA\Response(
        response: 200,
        description: 'El desenlace de la tarea.',
        content: new OA\JsonContent(
            required: ['task_id', 'status', 'final_video_url'],
            properties: [
                new OA\Property(property: 'task_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'status', type: 'string'),
                new OA\Property(property: 'final_video_url', type: 'string', nullable: true),
            ],
            type: 'object',
        ),
    )]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    public function final(string $id): JsonResponse
    {
        $view = $this->view($id);

        if (null === $view) {
            return self::notFound();
        }

        return new JsonResponse([
            'task_id' => $view->summary->taskId,
            'status' => $view->summary->status,
            'final_video_url' => $view->summary->finalVideoUrl,
        ]);
    }

    /**
     * Cancels a task that has not finished. A pending task is never picked up;
     * a processing one is stopped by its worker at the next part boundary.
     * 409 when the task already completed, failed or was canceled.
     */
    #[Route('/{id}', name: 'api_tasks_cancel', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    #[OA\Delete(
        summary: 'Cancela una tarea sin terminar',
        description: 'Una tarea pendiente no se recoge; una en curso se detiene en la siguiente parte, conservando los clips ya generados.',
    )]
    #[OA\Parameter(ref: '#/components/parameters/taskId')]
    #[OA\Response(response: 200, description: 'La tarea, ya cancelada.', content: new OA\JsonContent(ref: '#/components/schemas/Task'))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    public function cancel(string $id): JsonResponse
    {
        $this->commandBus->dispatch(new CancelVideoTaskCommand($id));

        return $this->taskResponse($id, Response::HTTP_OK);
    }

    /**
     * Queues a failed or canceled task again. Completed parts are kept; the
     * rest are rendered afresh. 409 for any other status.
     */
    #[Route('/{id}/retry', name: 'api_tasks_retry', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[OA\Post(
        summary: 'Reencola una tarea fallida o cancelada',
        description: 'Las partes completadas se conservan con su vídeo; sólo se vuelve a renderizar lo que falta.',
    )]
    #[OA\Parameter(ref: '#/components/parameters/taskId')]
    #[OA\Response(response: 202, description: 'La tarea, de vuelta en la cola.', content: new OA\JsonContent(ref: '#/components/schemas/Task'))]
    #[OA\Response(response: 404, ref: '#/components/responses/NotFound')]
    #[OA\Response(response: 409, ref: '#/components/responses/Conflict')]
    public function retry(string $id): JsonResponse
    {
        $this->commandBus->dispatch(new RetryVideoTaskCommand($id));

        return $this->taskResponse($id, Response::HTTP_ACCEPTED);
    }

    private function taskResponse(string $id, int $status): JsonResponse
    {
        $view = $this->view($id);

        if (null === $view) {
            return self::notFound();
        }

        return new JsonResponse($view->toArray(), $status);
    }

    private function view(string $id): ?VideoTaskView
    {
        $result = $this->queryBus->dispatch(new GetVideoTaskQuery($id))->last(HandledStamp::class)?->getResult();

        return $result instanceof VideoTaskView ? $result : null;
    }

    private static function notFound(): JsonResponse
    {
        return ApiProblem::response(Response::HTTP_NOT_FOUND, 'No existe ninguna tarea con ese identificador.');
    }

    /**
     * @param string $rootField the field a violation of the object as a whole
     *                          is reported under
     */
    private static function validationFailed(ConstraintViolationListInterface $violations, string $rootField): JsonResponse
    {
        $errors = [];

        foreach ($violations as $violation) {
            $field = (string) $violation->getPropertyPath();
            $errors['' === $field ? $rootField : $field][] = (string) $violation->getMessage();
        }

        return ApiProblem::response(
            Response::HTTP_BAD_REQUEST,
            'La petición no supera la validación.',
            ['violations' => $errors],
        );
    }
}

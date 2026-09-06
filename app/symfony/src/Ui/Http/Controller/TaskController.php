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
final readonly class TaskController
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private MessageBusInterface $queryBus,
        private ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_tasks_create', methods: ['POST'])]
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
        $envelope = $this->commandBus->dispatch(new CreateVideoTaskCommand($dto->toImageList(), $dto->callbackUrl()));
        $taskId = $envelope->last(HandledStamp::class)?->getResult();

        if (!\is_string($taskId)) {
            // A 201 whose body says "task_id": null is worse than an error: the
            // client has nothing to poll and no reason to retry.
            return ApiProblem::response(Response::HTTP_INTERNAL_SERVER_ERROR, 'No se pudo crear la tarea.');
        }

        return new JsonResponse(['task_id' => $taskId, 'status' => 'pending'], Response::HTTP_CREATED);
    }

    #[Route('', name: 'api_tasks_list', methods: ['GET'])]
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
    public function get(string $id): JsonResponse
    {
        $view = $this->view($id);

        if (null === $view) {
            return self::notFound();
        }

        return new JsonResponse($view->toArray());
    }

    #[Route('/{id}/final', name: 'api_tasks_final', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
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

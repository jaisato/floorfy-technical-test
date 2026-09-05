<?php

declare(strict_types=1);

namespace App\Ui\Http\Controller;

use App\Task\Application\Command\CreateVideoTaskCommand;
use App\Task\Application\DTO\VideoTaskView;
use App\Task\Application\Query\GetVideoTaskQuery;
use App\Ui\Http\Request\CreateTaskRequest;
use App\Ui\Http\Response\ApiProblem;
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
            return self::validationFailed($violations);
        }

        // The command carries what was validated, not the body that arrived:
        // persisting the raw payload stored unvalidated extra fields and left
        // the stored images out of step with the ones actually queued.
        $envelope = $this->commandBus->dispatch(new CreateVideoTaskCommand($dto->toImageList()));
        $taskId = $envelope->last(HandledStamp::class)?->getResult();

        if (!\is_string($taskId)) {
            // A 201 whose body says "task_id": null is worse than an error: the
            // client has nothing to poll and no reason to retry.
            return ApiProblem::response(Response::HTTP_INTERNAL_SERVER_ERROR, 'No se pudo crear la tarea.');
        }

        return new JsonResponse(['task_id' => $taskId, 'status' => 'pending'], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_tasks_get', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $view = $this->view($id);

        if (null === $view) {
            return self::notFound();
        }

        return new JsonResponse([
            'task_id' => $view->taskId,
            'status' => $view->status,
            'error' => $view->error,
            'partial_videos' => $view->partialVideosAsArray(),
        ]);
    }

    #[Route('/{id}/final', name: 'api_tasks_final', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function final(string $id): JsonResponse
    {
        $view = $this->view($id);

        if (null === $view) {
            return self::notFound();
        }

        return new JsonResponse([
            'task_id' => $view->taskId,
            'status' => $view->status,
            'final_video_url' => $view->finalVideoUrl,
        ]);
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

    private static function validationFailed(ConstraintViolationListInterface $violations): JsonResponse
    {
        $errors = [];

        foreach ($violations as $violation) {
            $field = (string) $violation->getPropertyPath();
            $errors['' === $field ? 'images' : $field][] = (string) $violation->getMessage();
        }

        return ApiProblem::response(
            Response::HTTP_BAD_REQUEST,
            'La petición no supera la validación.',
            ['violations' => $errors],
        );
    }
}

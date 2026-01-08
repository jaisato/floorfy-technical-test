<?php
declare(strict_types=1);

namespace App\Ui\Http\Controller;

use App\Task\Application\Command\CreateVideoTaskCommand;
use App\Task\Application\Query\GetVideoTaskQuery;
use App\Task\Application\DTO\VideoTaskView;
use App\Ui\Http\Request\CreateTaskRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class TaskController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly MessageBusInterface $queryBus,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('/api/tasks', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->badRequest('Invalid JSON payload');
        }

        $dto = CreateTaskRequest::fromArray($payload);
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return $this->validationBadRequest($errors);
        }

        $images = [];
        foreach ($dto->images as $img) {
            $images[] = [
                'url' => (string) $img['url'],
                'transition' => (string) $img['transition'],
            ];
        }

        $envelope = $this->commandBus->dispatch(new CreateVideoTaskCommand(
            $images,
            $payload,
        ));

        $taskId = $envelope->last(HandledStamp::class)?->getResult();

        return new JsonResponse([
            'task_id' => $taskId,
            'status' => 'pending',
        ], 201);
    }

    #[Route('/api/tasks/{id}', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        $envelope = $this->queryBus->dispatch(new GetVideoTaskQuery($id));
        /** @var VideoTaskView|null $view */
        $view = $envelope->last(HandledStamp::class)?->getResult();

        if ($view === null) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        return new JsonResponse([
            'task_id' => $view->taskId,
            'status' => $view->status,
            'partial_videos' => $view->partialVideos,
        ], 200);
    }

    #[Route('/api/tasks/{id}/final', methods: ['GET'])]
    public function final(string $id): JsonResponse
    {
        $envelope = $this->queryBus->dispatch(new GetVideoTaskQuery($id));
        /** @var VideoTaskView|null $view */
        $view = $envelope->last(HandledStamp::class)?->getResult();

        if ($view === null) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        return new JsonResponse([
            'task_id' => $view->taskId,
            'status' => $view->status,
            'final_video_url' => $view->finalVideoUrl,
        ], 200);
    }

    private function badRequest(string $message, array $context = []): JsonResponse
    {
        return new JsonResponse([
            'error' => $message,
            'context' => $context,
        ], 400);
    }

    private function validationBadRequest(ConstraintViolationListInterface $violations): JsonResponse
    {
        $errors = [];
        foreach ($violations as $violation) {
            $field = (string) $violation->getPropertyPath();
            if ($field === '') {
                $field = 'payload';
            }
            $errors[$field][] = $violation->getMessage();
        }

        return $this->badRequest('Validation failed', ['violations' => $errors]);
    }
}

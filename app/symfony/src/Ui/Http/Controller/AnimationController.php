<?php
declare(strict_types=1);

namespace App\Ui\Http\Controller;

use App\Animation\Application\Command\RequestAnimationCommand;
use App\Animation\Application\DTO\AnimationJobView;
use App\Animation\Application\Query\GetAnimationJobQuery;
use App\Ui\Http\Request\CreateAnimationRequest;
use App\Ui\Http\Response\ApiResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AnimationController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly MessageBusInterface $queryBus,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('/api/animations', methods: ['POST'])]
    public function create(Request $request)
    {
        $payload = json_decode($request->getContent(), true) ?: [];
        $dto = CreateAnimationRequest::fromArray($payload);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return ApiResponse::validationErrors($errors);
        }

        $envelope = $this->commandBus->dispatch(new RequestAnimationCommand(
            $dto->imageUrl,
            $dto->prompt,
        ));

        $jobId = $envelope->last(HandledStamp::class)?->getResult();

        return ApiResponse::ok(['id' => $jobId], 202);
    }

    #[Route('/api/animations/{id}', methods: ['GET'])]
    public function get(string $id)
    {
        $envelope = $this->queryBus->dispatch(new GetAnimationJobQuery($id));
        /** @var AnimationJobView $view */
        $view = $envelope->last(HandledStamp::class)?->getResult();

        if (!$view) {
            return ApiResponse::error('Not found', 404);
        }

        return ApiResponse::ok([
            'id' => $view->id,
            'imageUrl' => $view->imageUrl,
            'status' => $view->status,
            'videoUrl' => $view->videoUrl,
            'errorMessage' => $view->errorMessage,
            'createdAt' => $view->createdAt,
            'updatedAt' => $view->updatedAt,
        ]);
    }
}

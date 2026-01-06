<?php
declare(strict_types=1);

namespace App\Ui\Http\Controller;

use App\Composition\Application\Command\CreateCompositionCommand;
use App\Composition\Application\Query\GetCompositionJobQuery;
use App\Ui\Http\Request\CreateCompositionRequest;
use App\Ui\Http\Response\ApiResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CompositionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly MessageBusInterface $queryBus,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('/api/compositions', methods: ['POST'])]
    public function create(Request $request)
    {
        $payload = json_decode($request->getContent(), true) ?: [];
        $dto = CreateCompositionRequest::fromArray($payload);

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            return ApiResponse::validationErrors($errors);
        }

        $envelope = $this->commandBus->dispatch(new CreateCompositionCommand($dto->videoUrls));
        $id = $envelope->last(HandledStamp::class)?->getResult();

        return ApiResponse::ok(['id' => $id], 202);
    }

    #[Route('/api/compositions/{id}', methods: ['GET'])]
    public function get(string $id)
    {
        $envelope = $this->queryBus->dispatch(new GetCompositionJobQuery($id));
        $view = $envelope->last(HandledStamp::class)?->getResult();

        if (!$view) {
            return ApiResponse::error('Not found', 404);
        }

        return ApiResponse::ok((array) $view);
    }
}

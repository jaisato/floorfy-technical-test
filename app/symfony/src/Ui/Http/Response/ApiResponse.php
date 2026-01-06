<?php

declare(strict_types=1);

namespace App\Ui\Http\Response;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\ConstraintViolationListInterface;

final class ApiResponse
{
    public static function ok(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    public static function error(string $message, int $status = 400, array $context = []): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => [
                'message' => $message,
                'context' => $context,
            ],
        ], $status);
    }

    public static function validationErrors(ConstraintViolationListInterface $violations, int $status = 422): JsonResponse
    {
        $errors = [];

        foreach ($violations as $violation) {
            $field = (string) $violation->getPropertyPath();
            if ($field === '') {
                $field = 'payload';
            }

            $errors[$field][] = $violation->getMessage();
        }

        return new JsonResponse([
            'success' => false,
            'error' => [
                'message' => 'Validation failed',
                'violations' => $errors,
            ],
        ], $status);
    }
}

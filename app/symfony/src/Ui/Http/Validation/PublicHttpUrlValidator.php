<?php

declare(strict_types=1);

namespace App\Ui\Http\Validation;

use App\Task\Infrastructure\Media\PublicUrlGuard;
use App\Task\Infrastructure\Media\UrlNotFetchable;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class PublicHttpUrlValidator extends ConstraintValidator
{
    public function __construct(private readonly PublicUrlGuard $guard)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PublicHttpUrl) {
            throw new UnexpectedTypeException($constraint, PublicHttpUrl::class);
        }

        // Absent, empty or not text: other constraints say so; this one only
        // judges a URL that is there to be judged.
        if (!\is_string($value) || '' === $value) {
            return;
        }

        try {
            $this->guard->assertFetchable($value);
        } catch (UrlNotFetchable $e) {
            // The guard's messages are written for the caller: they name the
            // scheme, port or address class that was refused, not the address
            // it resolved to being anything the caller did not already know.
            //
            // A host that would not resolve is refused here too, though the
            // worker retries it. A submission is a conversation: the caller is
            // there to be told, and can send it again, which is a better answer
            // than accepting a task that is going to spend its retries and fail.
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ reason }}', $e->getMessage())
                ->addViolation();
        }
    }
}

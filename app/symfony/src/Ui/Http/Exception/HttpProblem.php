<?php

declare(strict_types=1);

namespace App\Ui\Http\Exception;

use App\Shared\Domain\Exception\ClientSafe;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * An error the HTTP layer itself detects, with a message written for the
 * caller. Thrown where a Response cannot be returned directly (a request
 * listener), and turned into the usual problem document - with this message as
 * its detail - by ApiProblemListener.
 */
final class HttpProblem extends HttpException implements ClientSafe
{
    /** @param array<string, string> $headers */
    public function __construct(int $status, string $detail, array $headers = [])
    {
        parent::__construct($status, $detail, null, $headers);
    }
}

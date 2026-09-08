<?php

declare(strict_types=1);

namespace App\Ui\Http\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * The value is a URL this service may make a request to: http or https, on
 * port 80 or 443, resolving only to public addresses - the same rules the
 * image URLs face when they are fetched, applied at request time so that a
 * callback URL pointing inside the network is refused with a 400 rather than
 * discovered by the worker later, when there is nobody left to tell.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class PublicHttpUrl extends Constraint
{
    public string $message = 'La URL no es una dirección pública alcanzable: {{ reason }}';
}

<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Task\Application\Callback\CallbackDelivery;
use App\Task\Application\Callback\CallbackDeliveryFailed;
use App\Task\Application\Callback\CallbackRequest;

final class FakeCallbackDelivery implements CallbackDelivery
{
    /** @var list<CallbackRequest> */
    public array $delivered = [];

    private ?CallbackDeliveryFailed $failure = null;

    public function failWith(CallbackDeliveryFailed $failure): void
    {
        $this->failure = $failure;
    }

    public function deliver(CallbackRequest $request): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->delivered[] = $request;
    }
}

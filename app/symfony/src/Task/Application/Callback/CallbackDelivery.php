<?php

declare(strict_types=1);

namespace App\Task\Application\Callback;

/**
 * Hands a notification to the client's endpoint.
 */
interface CallbackDelivery
{
    /**
     * @throws CallbackDeliveryFailed when the endpoint did not accept it; the
     *                                exception says whether trying again could help
     */
    public function deliver(CallbackRequest $request): void;
}

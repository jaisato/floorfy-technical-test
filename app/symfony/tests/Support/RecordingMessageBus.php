<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    /**
     * Whether a transaction was open at the moment of each dispatch. A message
     * published before the rows are committed can reach a worker that cannot
     * see the task yet, or outlive a transaction that rolled back.
     *
     * @var list<bool>
     */
    public array $insideTransaction = [];

    public function __construct(private readonly SpyTransaction $transaction = new SpyTransaction())
    {
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = $message;
        $this->insideTransaction[] = $this->transaction->running;

        return new Envelope($message);
    }
}

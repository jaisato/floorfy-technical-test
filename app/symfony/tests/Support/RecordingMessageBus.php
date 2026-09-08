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

    /** Set to make the next dispatch fail, as a broker that is down does. */
    public ?\Throwable $failure = null;

    /**
     * Failures for one kind of message only.
     *
     * The two recovered messages travel on separately configured transports, so
     * one of them being unreachable says nothing about the other - which is the
     * whole point of not letting the first failure end the run.
     *
     * @var array<class-string, \Throwable>
     */
    private array $failures = [];

    /** @param class-string $messageClass */
    public function failFor(string $messageClass, \Throwable $error): void
    {
        $this->failures[$messageClass] = $error;
    }

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = $message;
        $this->insideTransaction[] = $this->transaction->running;

        $failure = $this->failures[$message::class] ?? $this->failure;

        if (null !== $failure) {
            throw $failure;
        }

        return new Envelope($message);
    }
}

<?php

declare(strict_types=1);

namespace Marko\Queue;

use Marko\Core\Event\AsyncObserverDispatcherInterface;
use Marko\Core\Event\Event;
use Marko\Queue\Exceptions\SerializationException;
use Throwable;

/**
 * Queues #[Observer(async: true)] observers as AsyncObserverJob instances.
 *
 * The event is serialized and wrapped in a signed JobEnvelope, which is the
 * form AsyncObserverJob verifies when a worker (or the sync driver) runs it.
 */
readonly class QueueAsyncObserverDispatcher implements AsyncObserverDispatcherInterface
{
    public function __construct(
        private QueueInterface $queue,
        private JobEnvelope $jobEnvelope,
    ) {}

    /**
     * @throws SerializationException
     */
    public function dispatch(
        string $observerClass,
        Event $event,
    ): void {
        try {
            $serializedEvent = serialize($event);
        } catch (Throwable $e) {
            throw SerializationException::unserializableEvent($observerClass, $event::class, $e);
        }

        $this->queue->push(new AsyncObserverJob(
            $observerClass,
            $this->jobEnvelope->wrap($serializedEvent),
        ));
    }
}

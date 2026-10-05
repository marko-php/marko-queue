<?php

declare(strict_types=1);

use Marko\Core\Event\AsyncObserverDispatcherInterface;
use Marko\Core\Event\Event;
use Marko\Encryption\Config\EncryptionConfig;
use Marko\Queue\AsyncObserverJob;
use Marko\Queue\Exceptions\SerializationException;
use Marko\Queue\JobEnvelope;
use Marko\Queue\QueueAsyncObserverDispatcher;
use Marko\Testing\Fake\FakeConfigRepository;
use Marko\Testing\Fake\FakeQueue;

class QueueDispatcherTestEvent extends Event
{
    public function __construct(
        public readonly string $orderNumber,
    ) {}
}

class QueueDispatcherClosureEvent extends Event
{
    public function __construct(
        public readonly Closure $callback,
    ) {}
}

function createQueueDispatcherEnvelope(): JobEnvelope
{
    return new JobEnvelope(
        new EncryptionConfig(new FakeConfigRepository(['encryption.key' => 'queue-dispatcher-key'])),
    );
}

describe('QueueAsyncObserverDispatcher', function (): void {
    it('implements AsyncObserverDispatcherInterface', function (): void {
        $dispatcher = new QueueAsyncObserverDispatcher(new FakeQueue(), createQueueDispatcherEnvelope());

        expect($dispatcher)->toBeInstanceOf(AsyncObserverDispatcherInterface::class);
    });

    it('pushes an AsyncObserverJob carrying the observer class', function (): void {
        $queue = new FakeQueue();
        $dispatcher = new QueueAsyncObserverDispatcher($queue, createQueueDispatcherEnvelope());

        $dispatcher->dispatch('App\Observer\SendReceipt', new QueueDispatcherTestEvent('A-100'));

        expect($queue->pushed)->toHaveCount(1)
            ->and($queue->pushed[0]['job'])->toBeInstanceOf(AsyncObserverJob::class)
            ->and($queue->pushed[0]['job']->observerClass)->toBe('App\Observer\SendReceipt');
    });

    it('wraps the serialized event in a signed job envelope', function (): void {
        $queue = new FakeQueue();
        $envelope = createQueueDispatcherEnvelope();
        $dispatcher = new QueueAsyncObserverDispatcher($queue, $envelope);

        $dispatcher->dispatch('App\Observer\SendReceipt', new QueueDispatcherTestEvent('A-100'));

        /** @var AsyncObserverJob $job */
        $job = $queue->pushed[0]['job'];
        $event = unserialize($envelope->verifyAndUnwrap($job->eventData));

        expect($job->eventData)->not->toBe(serialize(new QueueDispatcherTestEvent('A-100')))
            ->and($event)->toBeInstanceOf(QueueDispatcherTestEvent::class)
            ->and($event->orderNumber)->toBe('A-100');
    });

    it('throws a SerializationException naming the observer when the event cannot be serialized', function (): void {
        $queue = new FakeQueue();
        $dispatcher = new QueueAsyncObserverDispatcher($queue, createQueueDispatcherEnvelope());
        $thrown = null;

        try {
            $dispatcher->dispatch('App\Observer\SendReceipt', new QueueDispatcherClosureEvent(fn (): null => null));
        } catch (SerializationException $e) {
            $thrown = $e;
        }

        expect($thrown)->toBeInstanceOf(SerializationException::class)
            ->and($thrown?->getMessage())->toContain('App\Observer\SendReceipt')
            ->and($thrown?->getContext())->toContain(QueueDispatcherClosureEvent::class)
            ->and($thrown?->getPrevious())->toBeInstanceOf(Exception::class)
            ->and($queue->pushed)->toBeEmpty();
    });
});

<?php

declare(strict_types=1);

namespace Marko\Queue;

use Marko\Core\Container\ContainerInterface;
use Marko\Queue\Exceptions\SerializationException;
use RuntimeException;

class AsyncObserverJob extends Job implements ContainerAwareJobInterface
{
    private ?ContainerInterface $container = null;

    private ?JobEnvelope $jobEnvelope = null;

    public function __construct(
        public readonly string $observerClass,
        public readonly string $eventData,
    ) {}

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function setJobEnvelope(JobEnvelope $jobEnvelope): void
    {
        $this->jobEnvelope = $jobEnvelope;
    }

    /**
     * Execute the async observer job.
     *
     * Resolves the observer from the container and invokes its handle() method
     * with the deserialized event. When a JobEnvelope has been set, the eventData
     * is treated as an HMAC-signed envelope and verified before unserializing.
     *
     * The container and envelope are released afterwards, even on failure: the
     * worker serializes a job that has used up its attempts into failed_jobs, and
     * the container cannot be serialized.
     *
     * @throws SerializationException|RuntimeException
     */
    public function handle(): void
    {
        $container = $this->container;

        if ($container === null) {
            throw new RuntimeException(
                'AsyncObserverJob::handle() was called without a container. '
                . 'Call setContainer() before handle() to provide the DI container for observer resolution.',
            );
        }

        try {
            $rawEventData = $this->jobEnvelope !== null
                ? $this->jobEnvelope->verifyAndUnwrap($this->eventData)
                : $this->eventData;

            $event = unserialize($rawEventData);

            $observer = $container->get($this->observerClass);
            $observer->handle($event);
        } finally {
            $this->container = null;
            $this->jobEnvelope = null;
        }
    }
}

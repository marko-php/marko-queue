<?php

declare(strict_types=1);

namespace Marko\Queue;

use DateTimeImmutable;
use Marko\Core\Container\ContainerInterface;
use Marko\Queue\Exceptions\QueueException;
use Marko\Queue\Exceptions\SerializationException;
use Throwable;

class Worker implements WorkerInterface
{
    private bool $running = false;

    public function __construct(
        private readonly QueueInterface $queue,
        private readonly FailedJobRepositoryInterface $failedJobRepository,
        private readonly QueueConfig $config,
        private readonly JobEnvelope $jobEnvelope,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * @param list<string>|null $queues Queue names in priority order, or null for the default queue
     * @throws QueueException|SerializationException
     */
    public function work(
        ?array $queues = null,
        bool $once = false,
        int $sleep = 3,
    ): void {
        $queueNames = $this->resolveQueueNames($queues);
        $this->running = true;

        while ($this->running) {
            [$job, $queue] = $this->popNextJob($queueNames);

            if ($job === null) {
                if ($once) {
                    return;
                }
                $this->pause($sleep);

                continue;
            }

            try {
                if ($job instanceof ContainerAwareJobInterface) {
                    $job->setContainer($this->container);
                    $job->setJobEnvelope($this->jobEnvelope);
                }

                $job->incrementAttempts();
                $job->handle();
                $this->queue->delete($job->id);
            } catch (Throwable $e) {
                $this->handleFailedJob($job, $e, $queue ?? $this->config->queue());
            }

            if ($once) {
                return;
            }
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Wait before polling again once every queue is empty.
     */
    protected function pause(
        int $seconds,
    ): void {
        sleep($seconds);
    }

    /**
     * Validate the priority list. Null becomes [null], which pops the driver's default queue.
     *
     * @param array<mixed>|null $queues
     * @return list<?string>
     * @throws QueueException
     */
    private function resolveQueueNames(
        ?array $queues,
    ): array {
        if ($queues === null) {
            return [null];
        }

        if ($queues === []) {
            throw QueueException::noQueuesGiven();
        }

        if (!array_is_list($queues)) {
            throw QueueException::invalidQueueName('the queue list must be a list, not a keyed array');
        }

        foreach ($queues as $queue) {
            if (!is_string($queue) || trim($queue) === '') {
                throw QueueException::invalidQueueName(
                    'every queue name must be a non-blank string; got ' . var_export($queue, true),
                );
            }
        }

        /** @var list<string> $queues */
        return $queues;
    }

    /**
     * Pop from each queue in priority order and return the first job found with its queue name.
     *
     * @param list<?string> $queueNames
     * @return array{0: ?JobInterface, 1: ?string}
     */
    private function popNextJob(
        array $queueNames,
    ): array {
        foreach ($queueNames as $queueName) {
            $job = $this->queue->pop($queueName);

            if ($job !== null) {
                return [$job, $queueName];
            }
        }

        return [null, null];
    }

    /**
     * Seconds to wait before retrying a job whose latest attempt failed.
     *
     * Uses the job's $backoff, then the `queue.backoff` config, then the exponential
     * curve 2^attempts * 10. A list backoff is indexed by attempt and its last value repeats.
     *
     * @throws QueueException
     */
    public function backoffFor(
        JobInterface $job,
    ): int {
        if ($job->backoff !== null) {
            return $this->resolveBackoff($job->backoff, $job->attempts, 'job ' . $job::class);
        }

        $configBackoff = $this->config->backoff();

        if ($configBackoff !== null) {
            return $this->resolveBackoff($configBackoff, $job->attempts, 'config queue.backoff');
        }

        return (int) pow(2, $job->attempts) * 10;
    }

    /**
     * @param int|array<mixed> $backoff
     * @throws QueueException
     */
    private function resolveBackoff(
        array|int $backoff,
        int $attempts,
        string $source,
    ): int {
        if (is_int($backoff)) {
            if ($backoff < 0) {
                throw QueueException::invalidBackoff($source, "delay must not be negative; got $backoff");
            }

            return $backoff;
        }

        if ($backoff === [] || !array_is_list($backoff)) {
            throw QueueException::invalidBackoff($source, 'a backoff array must be a non-empty list of ints');
        }

        foreach ($backoff as $delay) {
            if (!is_int($delay) || $delay < 0) {
                throw QueueException::invalidBackoff(
                    $source,
                    'every backoff list entry must be a non-negative int; got ' . var_export($delay, true),
                );
            }
        }

        return $backoff[min(max($attempts, 1), count($backoff)) - 1];
    }

    /**
     * @throws SerializationException
     */
    private function handleFailedJob(
        JobInterface $job,
        Throwable $e,
        string $queue,
    ): void {
        $maxAttempts = $job->maxAttempts ?? $this->config->maxAttempts();

        if ($job->attempts < $maxAttempts) {
            $this->queue->release($job->id, $this->backoffFor($job));
        } else {
            $this->failedJobRepository->store(new FailedJob(
                id: $job->id,
                queue: $queue,
                payload: $this->jobEnvelope->wrap($job->serialize()),
                exception: $e->getMessage() . "\n" . $e->getTraceAsString(),
                failedAt: new DateTimeImmutable(),
            ));
            $this->queue->delete($job->id);
        }
    }
}

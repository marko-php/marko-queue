<?php

declare(strict_types=1);

namespace Marko\Queue;

use Marko\Core\Container\ContainerInterface;
use Marko\Queue\Exceptions\QueueException;
use Marko\Queue\Exceptions\SerializationException;
use Psr\Clock\ClockInterface;
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
        private readonly ClockInterface $clock,
        private readonly BackoffValidator $backoffValidator = new BackoffValidator(),
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
                $this->runJob($job);
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
     * Handle one attempt of the job.
     *
     * A container-aware job gets the container and envelope first, and releases them
     * afterwards whether handle() returns or throws: a job that fails for the last time
     * is serialized into failed_jobs, and the container cannot be serialized.
     *
     * @throws Throwable
     */
    private function runJob(
        JobInterface $job,
    ): void {
        if (!$job instanceof ContainerAwareJobInterface) {
            $job->incrementAttempts();
            $job->handle();

            return;
        }

        try {
            $job->setContainer($this->container);
            $job->setJobEnvelope($this->jobEnvelope);
            $job->incrementAttempts();
            $job->handle();
        } finally {
            $job->releaseContainer();
        }
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
     * @throws QueueException When the job's $backoff or the queue.backoff config is invalid
     */
    public function backoffFor(
        JobInterface $job,
    ): int {
        $backoff = $job->backoff !== null
            ? $this->backoffValidator->validate($job->backoff, 'job ' . $job::class)
            : $this->config->backoff();

        if ($backoff === null) {
            return (int) pow(2, $job->attempts) * 10;
        }

        if (is_int($backoff)) {
            return $backoff;
        }

        return $backoff[min(max($job->attempts, 1), count($backoff)) - 1];
    }

    /**
     * Release a failed job for another attempt, or record it in failed_jobs on its last attempt.
     *
     * A job whose backoff is invalid cannot be released, so it is recorded in failed_jobs with
     * both its own error and the backoff error, and the worker keeps running: one bad job class
     * must not stop every worker. Fix the backoff, then queue:retry the job.
     *
     * @throws QueueException|SerializationException
     */
    private function handleFailedJob(
        JobInterface $job,
        Throwable $e,
        string $queue,
    ): void {
        $maxAttempts = $job->maxAttempts ?? $this->config->maxAttempts();

        if ($job->attempts >= $maxAttempts) {
            $this->storeFailedJob($job, $e, $queue);

            return;
        }

        try {
            $delay = $this->backoffFor($job);
        } catch (QueueException $backoffError) {
            $this->storeFailedJob(
                $job,
                $e,
                $queue,
                "\n\nJob could not be retried: " . $backoffError->getMessage() . ' ' . $backoffError->getContext(),
            );

            return;
        }

        $this->queue->release($job->id, $delay);
    }

    /**
     * Store the job in failed_jobs and delete it from the queue.
     *
     * A job whose payload cannot be serialized (for example one holding a closure) is still
     * recorded, with a placeholder payload of its class and the serialization error, and
     * deleted from the queue, so the worker keeps running. queue:retry refuses that row.
     *
     * @throws SerializationException
     */
    private function storeFailedJob(
        JobInterface $job,
        Throwable $e,
        string $queue,
        string $note = '',
    ): void {
        $exception = $e->getMessage() . "\n" . $e->getTraceAsString() . $note;

        try {
            $serialized = $job->serialize();
        } catch (Throwable $serializationError) {
            $serialized = serialize([
                'class' => $job::class,
                'serialization_error' => $serializationError->getMessage(),
            ]);
            $exception .= "\n\nJob payload could not be serialized, so this failed job cannot be retried. "
                . 'Job class ' . $job::class . ': ' . $serializationError->getMessage();
        }

        $this->failedJobRepository->store(new FailedJob(
            id: $job->id,
            queue: $queue,
            payload: $this->jobEnvelope->wrap($serialized),
            exception: $exception,
            failedAt: $this->clock->now(),
        ));
        $this->queue->delete($job->id);
    }
}

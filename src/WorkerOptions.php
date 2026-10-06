<?php

declare(strict_types=1);

namespace Marko\Queue;

use Marko\Queue\Exceptions\QueueException;

/**
 * Limits for one worker run. Zero disables a limit.
 */
readonly class WorkerOptions
{
    /**
     * @param int $timeout Seconds one job may run before it is failed and the worker exits (needs ext-pcntl)
     * @param int $memory Megabytes of memory after which the worker exits once the current job is done
     * @param int $maxJobs Jobs to process before the worker exits
     * @throws QueueException When a limit is negative
     */
    public function __construct(
        public int $timeout = 0,
        public int $memory = 0,
        public int $maxJobs = 0,
    ) {
        foreach (['timeout' => $timeout, 'memory' => $memory, 'max-jobs' => $maxJobs] as $name => $value) {
            if ($value < 0) {
                throw QueueException::invalidWorkerOption($name, (string) $value);
            }
        }
    }
}

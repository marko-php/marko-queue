<?php

declare(strict_types=1);

namespace Marko\Queue;

interface WorkerInterface
{
    /**
     * Process jobs until stopped.
     *
     * Each pass pops the queues in the given order and processes the first job found,
     * so earlier queues are always drained first. The worker sleeps only when every
     * queue is empty. With $once, it processes at most one job across all queues.
     *
     * @param list<string>|null $queues Queue names in priority order, or null for the default queue
     */
    public function work(
        ?array $queues = null,
        bool $once = false,
        int $sleep = 3,
    ): void;

    public function stop(): void;
}

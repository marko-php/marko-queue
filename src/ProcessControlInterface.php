<?php

declare(strict_types=1);

namespace Marko\Queue;

use Closure;

/**
 * The process-level hooks the queue worker needs: signals, a per-job timer, memory and exit.
 *
 * The built-in PcntlProcessControl uses ext-pcntl. Tests bind a fake so no real signal or
 * alarm is ever sent.
 */
interface ProcessControlInterface
{
    /**
     * Whether signals and alarms work in this process. Without them the per-job timeout is
     * not enforced and SIGTERM/SIGINT stop the worker immediately.
     */
    public function isSupported(): bool;

    /**
     * Call $onTerminate when the process receives SIGTERM or SIGINT, instead of exiting.
     */
    public function listenForTermination(
        Closure $onTerminate,
    ): void;

    /**
     * Cancel any timer and restore the default signal handling.
     */
    public function stopListening(): void;

    /**
     * Call $onTimeout if the timer is still running after $seconds.
     */
    public function startTimer(
        int $seconds,
        Closure $onTimeout,
    ): void;

    public function cancelTimer(): void;

    /**
     * Memory allocated to the process, in bytes.
     */
    public function memoryUsage(): int;

    /**
     * End the process with the given exit status. The built-in implementation does not return.
     */
    public function terminate(
        int $status,
    ): void;
}

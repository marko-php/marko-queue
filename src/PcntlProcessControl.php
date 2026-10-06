<?php

declare(strict_types=1);

namespace Marko\Queue;

use Closure;

/**
 * Process control backed by ext-pcntl. Every signal method is a no-op when pcntl is not loaded,
 * so the worker still runs, without a per-job timeout or graceful shutdown.
 */
class PcntlProcessControl implements ProcessControlInterface
{
    private ?bool $previousAsyncSignals = null;

    public function isSupported(): bool
    {
        return function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_alarm');
    }

    public function listenForTermination(
        Closure $onTerminate,
    ): void {
        if (!$this->isSupported()) {
            return;
        }

        $this->enableAsyncSignals();
        pcntl_signal(SIGTERM, static fn () => $onTerminate());
        pcntl_signal(SIGINT, static fn () => $onTerminate());
    }

    public function stopListening(): void
    {
        if (!$this->isSupported()) {
            return;
        }

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);

        if ($this->previousAsyncSignals !== null) {
            pcntl_async_signals($this->previousAsyncSignals);
            $this->previousAsyncSignals = null;
        }
    }

    public function startTimer(
        int $seconds,
        Closure $onTimeout,
    ): void {
        if (!$this->isSupported()) {
            return;
        }

        $this->enableAsyncSignals();
        // Not restarting syscalls lets the alarm also interrupt a job blocked on I/O or sleep()
        pcntl_signal(SIGALRM, static fn () => $onTimeout(), false);
        pcntl_alarm($seconds);
    }

    public function cancelTimer(): void
    {
        if ($this->isSupported()) {
            pcntl_alarm(0);
        }
    }

    public function memoryUsage(): int
    {
        return memory_get_usage(true);
    }

    public function terminate(
        int $status,
    ): void {
        exit($status);
    }

    private function enableAsyncSignals(): void
    {
        if ($this->previousAsyncSignals === null) {
            $this->previousAsyncSignals = pcntl_async_signals(true);
        }
    }
}

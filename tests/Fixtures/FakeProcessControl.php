<?php

declare(strict_types=1);

namespace Marko\Queue\Tests\Fixtures;

use Closure;
use Marko\Queue\ProcessControlInterface;
use RuntimeException;

/**
 * Process control that records calls and lets a test fire SIGTERM or the job timer by hand,
 * so no real signal or alarm is ever sent.
 */
class FakeProcessControl implements ProcessControlInterface
{
    /** @var list<string> */
    public array $calls = [];

    public ?int $terminatedWith = null;

    private ?Closure $onTerminate = null;

    private ?Closure $onTimeout = null;

    public function __construct(
        private readonly bool $supported = true,
        public int $memoryBytes = 0,
    ) {}

    public function isSupported(): bool
    {
        return $this->supported;
    }

    public function listenForTermination(
        Closure $onTerminate,
    ): void {
        $this->calls[] = 'listen';
        $this->onTerminate = $onTerminate;
    }

    public function stopListening(): void
    {
        $this->calls[] = 'stopListening';
        $this->onTerminate = null;
    }

    public function startTimer(
        int $seconds,
        Closure $onTimeout,
    ): void {
        $this->calls[] = "startTimer:$seconds";
        $this->onTimeout = $onTimeout;
    }

    public function cancelTimer(): void
    {
        $this->calls[] = 'cancelTimer';
        $this->onTimeout = null;
    }

    public function memoryUsage(): int
    {
        return $this->memoryBytes;
    }

    public function terminate(
        int $status,
    ): void {
        $this->calls[] = "terminate:$status";
        $this->terminatedWith = $status;
    }

    /**
     * Simulate SIGTERM or SIGINT arriving.
     */
    public function sendTerminationSignal(): void
    {
        if ($this->onTerminate === null) {
            throw new RuntimeException('The worker is not listening for termination signals.');
        }

        ($this->onTerminate)();
    }

    /**
     * Simulate the job timer running out.
     */
    public function fireTimer(): void
    {
        if ($this->onTimeout === null) {
            throw new RuntimeException('No job timer is running.');
        }

        ($this->onTimeout)();
    }
}

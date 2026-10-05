<?php

declare(strict_types=1);

namespace Marko\Queue\Tests\Fixtures;

use Marko\Queue\Job;

/**
 * Job with private and protected state, so serialize() emits NUL bytes.
 */
class PrivatePropertyJob extends Job
{
    public function __construct(
        private string $secret,
        protected string $shared,
    ) {}

    public function secret(): string
    {
        return $this->secret;
    }

    public function shared(): string
    {
        return $this->shared;
    }

    public function handle(): void {}
}

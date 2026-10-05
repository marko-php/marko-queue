<?php

declare(strict_types=1);

namespace Marko\Queue;

interface JobInterface
{
    public ?string $id { get; }

    public int $attempts { get; }

    /**
     * Maximum attempts for this job, or null to use the `queue.max_attempts` config default.
     */
    public ?int $maxAttempts { get; }

    public function handle(): void;

    public function setId(string $id): void;

    public function incrementAttempts(): void;

    public function resetAttempts(): void;

    public function serialize(): string;

    public static function unserialize(string $data): static;
}

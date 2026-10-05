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

    /**
     * Seconds to wait before retrying this job after a failed attempt.
     *
     * An int is a fixed delay for every retry. A list gives the delay per attempt
     * (the first entry after attempt 1, the second after attempt 2, ...), and its
     * last value repeats once the list runs out. Null uses the `queue.backoff` config.
     *
     * @var int|list<int>|null
     */
    public array|int|null $backoff { get; }

    public function handle(): void;

    public function setId(string $id): void;

    public function incrementAttempts(): void;

    public function resetAttempts(): void;

    public function serialize(): string;

    public static function unserialize(string $data): static;
}

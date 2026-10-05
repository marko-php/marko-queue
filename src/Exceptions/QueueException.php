<?php

declare(strict_types=1);

namespace Marko\Queue\Exceptions;

use Marko\Core\Exceptions\MarkoException;

class QueueException extends MarkoException
{
    public static function configFileNotFound(
        string $path,
    ): self {
        return new self(
            message: 'Queue configuration file queue.php not found.',
            context: "Expected config file at: $path",
            suggestion: 'Create a config/queue.php file with your queue configuration.',
        );
    }

    public static function noQueuesGiven(): self
    {
        return new self(
            message: 'No queues given to the queue worker.',
            context: 'The worker received an empty queue list.',
            suggestion: 'Pass queue names in priority order, e.g. --queue=high,default,'
                . ' or omit --queue to work the default queue.',
        );
    }

    public static function invalidQueueName(
        string $reason,
    ): self {
        return new self(
            message: 'Invalid queue name given to the queue worker.',
            context: $reason,
            suggestion: 'Pass queue names in priority order, e.g. --queue=high,default.',
        );
    }

    public static function invalidBackoff(
        string $source,
        string $reason,
    ): self {
        return new self(
            message: "Invalid queue backoff in $source.",
            context: $reason,
            suggestion: 'Set backoff to a non-negative int (fixed seconds), a non-empty list of non-negative ints'
                . ' (seconds per attempt; the last value repeats), or null (2^attempts * 10 seconds).',
        );
    }
}

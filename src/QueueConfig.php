<?php

declare(strict_types=1);

namespace Marko\Queue;

use Marko\Config\ConfigRepositoryInterface;
use Marko\Queue\Exceptions\QueueException;

readonly class QueueConfig
{
    public function __construct(
        private ConfigRepositoryInterface $config,
    ) {}

    public function driver(): string
    {
        return $this->config->getString('queue.driver');
    }

    public function connection(): string
    {
        return $this->config->getString('queue.connection');
    }

    public function queue(): string
    {
        return $this->config->getString('queue.queue');
    }

    public function retryAfter(): int
    {
        return $this->config->getInt('queue.retry_after');
    }

    public function maxAttempts(): int
    {
        return $this->config->getInt('queue.max_attempts');
    }

    /**
     * The default retry backoff: an int (fixed seconds), a list of seconds per attempt,
     * or null for the built-in exponential curve (2^attempts * 10 seconds).
     *
     * An app config that sets `backoff` to null removes the key during config merging,
     * so an absent key also means null.
     *
     * @return int|list<int>|null
     * @throws QueueException
     */
    public function backoff(): array|int|null
    {
        if (!$this->config->has('queue.backoff')) {
            return null;
        }

        $backoff = $this->config->get('queue.backoff');

        if ($backoff !== null && !is_int($backoff) && !is_array($backoff)) {
            throw QueueException::invalidBackoff(
                source: 'config queue.backoff',
                reason: 'expected an int, a list of ints, or null; got ' . get_debug_type($backoff),
            );
        }

        /** @var int|list<int>|null $backoff */
        return $backoff;
    }
}

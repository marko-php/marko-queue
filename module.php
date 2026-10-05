<?php

declare(strict_types=1);

use Marko\Core\Event\AsyncObserverDispatcherInterface;
use Marko\Queue\JobEnvelope;
use Marko\Queue\QueueAsyncObserverDispatcher;
use Marko\Queue\Worker;
use Marko\Queue\WorkerInterface;

return [
    'bindings' => [
        AsyncObserverDispatcherInterface::class => QueueAsyncObserverDispatcher::class,
        JobEnvelope::class => JobEnvelope::class,
        WorkerInterface::class => Worker::class,
    ],
];

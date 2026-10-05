<?php

declare(strict_types=1);

use Marko\Queue\JobEnvelope;
use Marko\Queue\Worker;
use Marko\Queue\WorkerInterface;

return [
    'bindings' => [
        JobEnvelope::class => JobEnvelope::class,
        WorkerInterface::class => Worker::class,
    ],
];

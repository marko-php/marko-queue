<?php

declare(strict_types=1);

use Marko\Queue\JobEnvelope;
use Marko\Queue\Worker;
use Marko\Queue\WorkerInterface;

describe('queue module.php', function (): void {
    it('binds WorkerInterface to Worker in module.php', function (): void {
        $module = require dirname(__DIR__) . '/module.php';

        expect($module['bindings'])->toHaveKey(WorkerInterface::class)
            ->and($module['bindings'][WorkerInterface::class])->toBe(Worker::class);
    });

    it('binds JobEnvelope to itself', function (): void {
        $module = require dirname(__DIR__) . '/module.php';

        expect($module['bindings'][JobEnvelope::class])->toBe(JobEnvelope::class);
    });
});

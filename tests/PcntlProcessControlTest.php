<?php

declare(strict_types=1);

use Marko\Queue\PcntlProcessControl;
use Marko\Queue\ProcessControlInterface;

describe('PcntlProcessControl', function (): void {
    it('implements ProcessControlInterface', function (): void {
        expect(new PcntlProcessControl())->toBeInstanceOf(ProcessControlInterface::class);
    });

    it('is supported exactly when the pcntl functions it uses exist', function (): void {
        $expected = function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_alarm');

        expect(new PcntlProcessControl()->isSupported())->toBe($expected);
    });

    it('reports the memory allocated to the process', function (): void {
        expect(new PcntlProcessControl()->memoryUsage())->toBe(memory_get_usage(true));
    });

    it('restores the previous async-signals setting when it stops listening', function (): void {
        $control = new PcntlProcessControl();

        if (!$control->isSupported()) {
            $control->listenForTermination(fn () => null);
            $control->stopListening();
            expect($control->isSupported())->toBeFalse();

            return;
        }

        $before = pcntl_async_signals();

        $control->listenForTermination(fn () => null);
        $during = pcntl_async_signals();
        $control->stopListening();

        expect($during)->toBeTrue()
            ->and(pcntl_async_signals())->toBe($before)
            ->and(pcntl_signal_get_handler(SIGTERM))->toBe(SIG_DFL);
    });
});

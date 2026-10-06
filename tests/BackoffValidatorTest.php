<?php

declare(strict_types=1);

use Marko\Queue\BackoffValidator;
use Marko\Queue\Exceptions\QueueException;

describe('BackoffValidator', function (): void {
    it('accepts a non-negative int, a non-empty list of non-negative ints', function (): void {
        $validator = new BackoffValidator();

        expect($validator->validate(0, 'job App\\SendEmail'))->toBe(0)
            ->and($validator->validate(30, 'job App\\SendEmail'))->toBe(30)
            ->and($validator->validate([5, 30, 120], 'job App\\SendEmail'))->toBe([5, 30, 120]);
    });

    it(
        'rejects a negative int, an empty list, a keyed array, and non-int or negative list entries',
        function (mixed $backoff, string $reason): void {
            $validator = new BackoffValidator();

            try {
                $validator->validate($backoff, 'job App\\SendEmail');
            } catch (QueueException $e) {
                expect($e->getMessage())->toBe('Invalid queue backoff in job App\\SendEmail.')
                    ->and($e->getContext())->toContain($reason);

                return;
            }

            throw new RuntimeException('Expected QueueException was not thrown');
        },
    )->with([
        'negative int' => [-1, 'delay must not be negative; got -1'],
        'empty list' => [[], 'a backoff array must be a non-empty list of ints'],
        'keyed array' => [['a' => 10], 'a backoff array must be a non-empty list of ints'],
        'non-int entry' => [[10, 'soon'], "got 'soon'"],
        'negative entry' => [[10, -5], 'got -5'],
    ]);

    it('rejects a value that is not an int or array', function (): void {
        $validator = new BackoffValidator();

        expect(fn () => $validator->validate('30', 'config queue.backoff'))
            ->toThrow(QueueException::class, 'Invalid queue backoff in config queue.backoff.');
    });
});

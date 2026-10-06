<?php

declare(strict_types=1);

use Marko\Core\Attributes\Command;
use Marko\Core\Command\CommandInterface;
use Marko\Core\Command\Input;
use Marko\Queue\Command\WorkCommand;
use Marko\Queue\QueueConfig;
use Marko\Queue\Tests\Command\Helpers;
use Marko\Queue\WorkerInterface;
use Marko\Testing\Fake\FakeConfigRepository;

/**
 * Worker stub that records the arguments queue:work passes to it.
 */
class CapturingWorker implements WorkerInterface
{
    public bool $called = false;

    /** @var list<string>|null */
    public ?array $queues = null;

    public ?bool $once = null;

    public ?int $sleep = null;

    public function work(
        ?array $queues = null,
        bool $once = false,
        int $sleep = 3,
    ): void {
        $this->called = true;
        $this->queues = $queues;
        $this->once = $once;
        $this->sleep = $sleep;
    }

    public function stop(): void {}
}

/**
 * Helper to execute WorkCommand and return output.
 *
 * @param array<string> $args
 *
 * @return array{output: string, exitCode: int}
 */
function executeWorkCommand(
    WorkCommand $command,
    array $args = ['marko', 'queue:work'],
): array {
    ['stream' => $stream, 'output' => $output] = Helpers::createOutputStream();
    $flags = new ReflectionClass(WorkCommand::class)->getAttributes(Command::class)[0]->newInstance()->flags;
    $input = new Input($args, $flags);

    $exitCode = $command->execute($input, $output);
    $result = Helpers::getOutputContent($stream);

    return ['output' => $result, 'exitCode' => $exitCode];
}

/**
 * Queue config for queue:work, with no backoff unless one is given.
 *
 * @param array<string, mixed> $values
 */
function createWorkCommandConfig(
    array $values = [],
): QueueConfig {
    return new QueueConfig(new FakeConfigRepository($values));
}

it('registers as queue:work command via #[Command] attribute', function (): void {
    $reflection = new ReflectionClass(WorkCommand::class);
    $attributes = $reflection->getAttributes(Command::class);

    expect($attributes)->toHaveCount(1)
        ->and($attributes[0]->newInstance()->name)->toBe('queue:work');
});

it('implements CommandInterface', function (): void {
    $reflection = new ReflectionClass(WorkCommand::class);

    expect($reflection->implementsInterface(CommandInterface::class))->toBeTrue();
});

it('processes jobs continuously', function (): void {
    $jobsProcessed = 0;
    $maxJobs = 3;

    // Create a worker that processes a few jobs then stops
    $worker = new class ($jobsProcessed, $maxJobs) implements WorkerInterface
    {
        public function __construct(
            private int &$processed,
            private readonly int $max,
        ) {}

        public function work(
            ?array $queues = null,
            bool $once = false,
            int $sleep = 3,
        ): void {
            // Simulate processing multiple jobs
            while ($this->processed < $this->max) {
                $this->processed++;
            }
        }

        public function stop(): void {}
    };

    $command = new WorkCommand($worker, createWorkCommandConfig());
    ['exitCode' => $exitCode] = executeWorkCommand($command);

    expect($jobsProcessed)->toBe(3)
        ->and($exitCode)->toBe(0);
});

it('supports once flag', function (): void {
    $worker = new CapturingWorker();

    executeWorkCommand(new WorkCommand($worker, createWorkCommandConfig()), ['marko', 'queue:work', '--once']);

    expect($worker->once)->toBeTrue();
});

it('passes a single queue as a one-item list', function (): void {
    $worker = new CapturingWorker();

    executeWorkCommand(new WorkCommand($worker, createWorkCommandConfig()), ['marko', 'queue:work', '--queue=emails']);

    expect($worker->queues)->toBe(['emails']);
});

it('works the emails queue for queue:work --queue emails', function (): void {
    $worker = new CapturingWorker();

    executeWorkCommand(
        new WorkCommand($worker, createWorkCommandConfig()),
        ['marko', 'queue:work', '--once', '--queue', 'emails', '--sleep', '5'],
    );

    expect($worker->queues)->toBe(['emails'])
        ->and($worker->once)->toBeTrue()
        ->and($worker->sleep)->toBe(5);
});

it('parses --queue=a,b --sleep=1 into a queue list and sleep', function (): void {
    $worker = new CapturingWorker();

    executeWorkCommand(
        new WorkCommand($worker, createWorkCommandConfig()),
        ['marko', 'queue:work', '--queue=a,b', '--sleep=1'],
    );

    expect($worker->queues)->toBe(['a', 'b'])
        ->and($worker->sleep)->toBe(1);
});

it('keeps the priority order of --queue=high,default,low', function (): void {
    $worker = new CapturingWorker();

    executeWorkCommand(
        new WorkCommand($worker, createWorkCommandConfig()),
        ['marko', 'queue:work', '--queue=high,default,low'],
    );

    expect($worker->queues)->toBe(['high', 'default', 'low']);
});

it('trims whitespace around queue names', function (): void {
    $worker = new CapturingWorker();

    executeWorkCommand(
        new WorkCommand($worker, createWorkCommandConfig()),
        ['marko', 'queue:work', '--queue', ' high , low '],
    );

    expect($worker->queues)->toBe(['high', 'low']);
});

it('drops empty segments such as --queue=high,,low', function (): void {
    $worker = new CapturingWorker();

    executeWorkCommand(
        new WorkCommand($worker, createWorkCommandConfig()),
        ['marko', 'queue:work', '--queue=high,,low,'],
    );

    expect($worker->queues)->toBe(['high', 'low']);
});

it('passes null when no queue option is given', function (): void {
    $worker = new CapturingWorker();

    executeWorkCommand(new WorkCommand($worker, createWorkCommandConfig()));

    expect($worker->called)->toBeTrue()
        ->and($worker->queues)->toBeNull();
});

it('fails loudly when --queue contains no queue names', function (): void {
    $worker = new CapturingWorker();

    ['output' => $output, 'exitCode' => $exitCode] = executeWorkCommand(
        new WorkCommand($worker, createWorkCommandConfig()),
        ['marko', 'queue:work', '--queue=,'],
    );

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('--queue needs at least one queue name')
        ->and($worker->called)->toBeFalse();
});

it('declares once as a flag on queue:work', function (): void {
    $attribute = new ReflectionClass(WorkCommand::class)->getAttributes(Command::class)[0]->newInstance();

    expect($attribute->flags)->toBe(['once']);
});

it('supports sleep option', function (): void {
    $worker = new CapturingWorker();

    executeWorkCommand(new WorkCommand($worker, createWorkCommandConfig()), ['marko', 'queue:work', '--sleep=5']);

    expect($worker->sleep)->toBe(5);
});

it('displays processing status', function (): void {
    ['output' => $output] = executeWorkCommand(new WorkCommand(new CapturingWorker(), createWorkCommandConfig()));

    expect($output)->toContain('Processing jobs from queue');
});

it('refuses to start with an invalid queue.backoff config', function (): void {
    ['output' => $output, 'exitCode' => $exitCode] = executeWorkCommand(
        new WorkCommand(new CapturingWorker(), createWorkCommandConfig(['queue.backoff' => [10, -5]])),
    );

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('Invalid queue backoff in config queue.backoff.')
        ->and($output)->toContain('every backoff list entry must be a non-negative int; got -5')
        ->and($output)->toContain('Set backoff to a non-negative int')
        ->and($output)->not->toContain('Processing jobs from queue');
});

it('does not start the worker when queue.backoff is invalid', function (): void {
    $worker = new CapturingWorker();

    executeWorkCommand(new WorkCommand($worker, createWorkCommandConfig(['queue.backoff' => -1])));

    expect($worker->called)->toBeFalse();
});

it('starts the worker when queue.backoff is valid', function (): void {
    $worker = new CapturingWorker();

    ['exitCode' => $exitCode] = executeWorkCommand(
        new WorkCommand($worker, createWorkCommandConfig(['queue.backoff' => [5, 30]])),
    );

    expect($exitCode)->toBe(0)
        ->and($worker->called)->toBeTrue();
});

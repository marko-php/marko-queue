<?php

declare(strict_types=1);

namespace Marko\Queue\Command;

use Marko\Core\Attributes\Command;
use Marko\Core\Command\CommandInterface;
use Marko\Core\Command\Input;
use Marko\Core\Command\Output;
use Marko\Queue\WorkerInterface;

/** @noinspection PhpUnused */
#[Command(name: 'queue:work', description: 'Process jobs from the queue', flags: ['once'])]
class WorkCommand implements CommandInterface
{
    private const int DEFAULT_SLEEP = 3;

    public function __construct(
        private readonly WorkerInterface $worker,
    ) {}

    public function execute(
        Input $input,
        Output $output,
    ): int {
        $once = $input->hasOption('once');
        $queueOption = $input->getOption('queue');
        $sleep = (int) ($input->getOption('sleep') ?? self::DEFAULT_SLEEP);
        $queues = $queueOption === null ? null : $this->parseQueues($queueOption);

        if ($queues === []) {
            $output->writeLine('Error: --queue needs at least one queue name, e.g. --queue=high,default.');

            return 1;
        }

        $output->writeLine('Processing jobs from queue...');

        $this->worker->work(queues: $queues, once: $once, sleep: $sleep);

        return 0;
    }

    /**
     * Split a comma-separated --queue value into queue names in priority order.
     *
     * @return list<string>
     */
    private function parseQueues(
        string $value,
    ): array {
        $names = array_map(trim(...), explode(',', $value));

        return array_values(array_filter($names, fn (string $name): bool => $name !== ''));
    }
}

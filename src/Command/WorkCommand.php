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
        $queue = $input->getOption('queue');
        $sleep = (int) ($input->getOption('sleep') ?? self::DEFAULT_SLEEP);

        $output->writeLine('Processing jobs from queue...');

        $this->worker->work(queue: $queue, once: $once, sleep: $sleep);

        return 0;
    }
}

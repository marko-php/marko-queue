<?php

declare(strict_types=1);

namespace Marko\Queue;

use Marko\Core\Container\ContainerInterface;

/**
 * A job that resolves services from the container when it runs, instead of serializing them.
 *
 * The worker (and the sync driver) call setContainer() and setJobEnvelope() before handle(),
 * and releaseContainer() after handle() returns or throws. Injected runtime services cannot be
 * serialized, and a job that fails for the last time is serialized into failed_jobs.
 */
interface ContainerAwareJobInterface
{
    public function setContainer(ContainerInterface $container): void;

    public function setJobEnvelope(JobEnvelope $jobEnvelope): void;

    /**
     * Drop every runtime service injected by setContainer() and setJobEnvelope(),
     * so the job holds only serializable data again.
     */
    public function releaseContainer(): void;
}

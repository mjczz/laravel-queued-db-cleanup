<?php

namespace Spatie\LaravelQueuedDbCleanup\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\LaravelQueuedDbCleanup\CleanConfig;
use Spatie\LaravelQueuedDbCleanup\Events\CleanDatabaseCompleted;
use Spatie\LaravelQueuedDbCleanup\Events\CleanDatabasePassCompleted;
use Spatie\LaravelQueuedDbCleanup\Events\CleanDatabasePassStarting;

class CleanDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public CleanConfig $config;

    public function __construct(CleanConfig $config)
    {
        $this->config = $config;
    }

    public function handle()
    {
        if (! $this->config->lock()->get()) {
            return;
        }

        $numberOfRowsDeleted = $this->performCleaning();

        $this->config->lock()->forceRelease();

        $this->config->rowsDeletedInThisPass($numberOfRowsDeleted);

        $this->config->shouldContinueCleaning()
            ? $this->continueCleaning()
            : $this->finishCleanup();
    }

    protected function performCleaning(): int
    {
        event(new CleanDatabasePassStarting($this->config));

        return $this->config->executeDeleteQuery();
    }

    protected function continueCleaning(): void
    {
        event(new CleanDatabasePassCompleted($this->config));

        $this->config->incrementPass();

        dispatch(new CleanDatabaseJob($this->config));
    }

    protected function finishCleanup(): void
    {
        event(new CleanDatabasePassCompleted($this->config));

        event(new CleanDatabaseCompleted($this->config));
    }
}
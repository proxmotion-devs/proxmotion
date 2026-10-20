<?php

declare(strict_types=1);

namespace App\Provisioning\Stages;

use App\Models\Task;

/**
 * A pipeline that runs in stages (docs/adr/0032): StageRunner runs one stage
 * at a time, and a stage that has to wait says how long, so the queue worker
 * is free in the meantime. Every stage is idempotent.
 */
interface StagedPipeline
{
    /**
     * The stages in order; the same list for every task of the pipeline.
     *
     * @return non-empty-list<string>
     */
    public function stages(): array;

    /**
     * Run one stage once.
     *
     * @return int|null null once the stage is done, else the seconds until it should run again
     */
    public function runStage(Task $task, string $stage): ?int;
}

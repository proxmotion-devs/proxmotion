<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\TaskResource;
use App\Models\Task;

class TaskController extends ApiController
{
    public function show(Task $task): TaskResource
    {
        return TaskResource::make($task->load('server'));
    }
}

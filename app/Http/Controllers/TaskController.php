<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TaskController
{
    /**
     * One task in the current company. Another company's task, or one the
     * user may not see, answers 404 (AGENTS.md §13.2).
     */
    public function __invoke(Request $request, int $task): View
    {
        /** @var User $user */
        $user = $request->user();
        $model = Task::query()->findOrFail($task);

        abort_unless($user->can('view', $model), 404);

        return view('tasks.show', ['task' => $model]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    /** Add a card to a project (lands at the bottom of the open column). */
    public function store(Request $request, Project $project): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);

        $project->tasks()->create([
            'title' => $data['title'],
            'category' => $data['category'] ?? null,
            'status' => 'open',
            'position' => (int) $project->tasks()->where('status', 'open')->max('position') + 1,
        ]);

        return back();
    }

    /** Move/edit a card (the board uses this to shift a card between columns). */
    public function update(Request $request, Task $task): RedirectResponse
    {
        abort_unless($task->project->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'status' => ['nullable', 'in:open,doing,done,cancelled'],
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        if (isset($data['status']) && $data['status'] !== $task->status) {
            $data['position'] = (int) $task->project->tasks()->where('status', $data['status'])->max('position') + 1;
        }

        $task->update(array_filter($data, fn ($v) => $v !== null));

        return back();
    }
}

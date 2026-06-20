<?php

namespace Tests;

use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Dev-on-server runs Vite via HMR with no built manifest (public/build is
        // removed so it doesn't shadow the dev server), so view-render tests that
        // hit @vite would throw ViteManifestNotFoundException. Tests don't care
        // about real assets — stub @vite out.
        $this->withoutVite();
    }

    /**
     * Create a task under a deliverable. Every task is a deliverable child (the
     * deliverable_id FK is required), so tests can't make a loose task; this helper
     * lazily creates a default deliverable on the task's project and attaches the
     * task to it. Pass `deliverable` in $attrs to use a specific one.
     */
    protected function makeTask(Project $project, array $attrs = []): Task
    {
        $deliverable = $attrs['deliverable'] ?? $this->defaultDeliverable($project);
        unset($attrs['deliverable'], $attrs['project_id']);

        return $deliverable->tasks()->create(array_merge([
            'project_id' => $project->id,
            'title' => 'Task',
            'status' => Task::STATUS_READY_FOR_PLANNING,
            'position' => 0,
        ], $attrs));
    }

    /** A lazily-created, reusable deliverable for a project (one per project per test). */
    protected function defaultDeliverable(Project $project): Deliverable
    {
        return $project->deliverables()->firstOrCreate(
            ['title' => 'Default test deliverable'],
            ['status' => Deliverable::STATUS_BUILDING, 'position' => 0],
        );
    }
}

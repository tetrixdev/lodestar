<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkSessionUiTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $owner): Project
    {
        return $owner->projects()->create(['name' => 'Demo', 'slug' => 'demo-'.uniqid()]);
    }

    public function test_index_lists_a_projects_sessions(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $project->workSessions()->create(['title' => 'Kickoff log', 'slug' => 'kickoff']);

        $this->actingAs($user)
            ->get(route('work-sessions.index', $project))
            ->assertOk()
            ->assertSee('Kickoff log');
    }

    public function test_creating_a_session_without_a_task_persists_and_shows(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $response = $this->actingAs($user)->post(route('work-sessions.store', $project), [
            'title' => 'Built the dashboard',
            'body' => 'Wired up the cards.',
            'body_summary' => 'Dashboard cards wired.',
            'occurred_on' => '2026-06-14',
        ]);

        $session = WorkSession::where('title', 'Built the dashboard')->firstOrFail();
        $response->assertRedirect(route('work-sessions.show', $session));

        $this->assertDatabaseHas('work_sessions', [
            'project_id' => $project->id,
            'title' => 'Built the dashboard',
            'task_id' => null,
        ]);

        $this->actingAs($user)
            ->get(route('work-sessions.show', $session))
            ->assertOk()
            ->assertSee('Built the dashboard')
            ->assertSee('Wired up the cards');
    }

    public function test_creating_a_session_with_a_task_links_it(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $task = $this->makeTask($project, ['title' => 'Some task', 'status' => Task::STATUS_READY_FOR_PLANNING, 'position' => 0]);

        $this->actingAs($user)->post(route('work-sessions.store', $project), [
            'title' => 'Worked on the task',
            'task_id' => $task->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('work_sessions', [
            'project_id' => $project->id,
            'title' => 'Worked on the task',
            'task_id' => $task->id,
        ]);

        $session = WorkSession::where('title', 'Worked on the task')->firstOrFail();
        $this->actingAs($user)
            ->get(route('work-sessions.show', $session))
            ->assertOk()
            ->assertSee('Some task');
    }

    public function test_title_is_required(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $this->actingAs($user)
            ->post(route('work-sessions.store', $project), ['title' => ''])
            ->assertSessionHasErrors('title');

        $this->assertDatabaseCount('work_sessions', 0);
    }

    public function test_task_must_belong_to_the_project(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $otherProject = $this->project($user);
        $foreignTask = $this->makeTask($otherProject, ['title' => 'Elsewhere', 'status' => Task::STATUS_READY_FOR_PLANNING, 'position' => 0]);

        $this->actingAs($user)
            ->post(route('work-sessions.store', $project), [
                'title' => 'Bad link',
                'task_id' => $foreignTask->id,
            ])
            ->assertSessionHasErrors('task_id');

        $this->assertDatabaseCount('work_sessions', 0);
    }

    public function test_slug_is_made_unique_within_the_project(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $this->actingAs($user)->post(route('work-sessions.store', $project), ['title' => 'Same Title']);
        $this->actingAs($user)->post(route('work-sessions.store', $project), ['title' => 'Same Title']);

        $slugs = $project->workSessions()->pluck('slug')->all();
        $this->assertCount(2, $slugs);
        $this->assertCount(2, array_unique($slugs));
    }

    public function test_stranger_cannot_view_a_session(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = $this->project($owner);
        $session = $project->workSessions()->create(['title' => 'Secret', 'slug' => 'secret']);

        $this->actingAs($stranger)
            ->get(route('work-sessions.show', $session))
            ->assertForbidden();
    }

    public function test_stranger_cannot_index_or_create(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = $this->project($owner);

        $this->actingAs($stranger)->get(route('work-sessions.index', $project))->assertForbidden();
        $this->actingAs($stranger)->get(route('work-sessions.create', $project))->assertForbidden();
        $this->actingAs($stranger)
            ->post(route('work-sessions.store', $project), ['title' => 'sneaky'])
            ->assertForbidden();
    }
}

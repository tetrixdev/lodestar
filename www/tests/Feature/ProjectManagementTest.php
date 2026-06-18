<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $user): Project
    {
        return $user->projects()->create([
            'name' => 'Demo',
            'slug' => 'demo-'.uniqid(),
        ]);
    }

    // ── editing content fields ───────────────────────────────────────────────

    public function test_update_persists_priority_and_due_date(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $task = $project->tasks()->create(['title' => 'A', 'status' => 'new', 'position' => 0]);

        $this->actingAs($user)
            ->patch("/tasks/{$task->id}", [
                'priority' => 'urgent',
                'due_date' => '2026-07-01',
                'title' => 'Renamed',
                'branch' => 'feat/x',
                'body' => 'desc',
                'body_summary' => 'desc tldr',
                'plan' => 'the plan',
                'plan_summary' => 'plan tldr',
                'start_date' => '2026-06-20',
            ])
            ->assertRedirect();

        $fresh = $task->fresh();
        $this->assertSame('urgent', $fresh->priority);
        $this->assertSame('2026-07-01', $fresh->due_date->format('Y-m-d'));
        $this->assertSame('2026-06-20', $fresh->start_date->format('Y-m-d'));
        $this->assertSame('Renamed', $fresh->title);
        $this->assertSame('feat/x', $fresh->branch);
        $this->assertSame('desc', $fresh->body);
        $this->assertSame('the plan', $fresh->plan);
        // status untouched.
        $this->assertSame('new', $fresh->status);
    }

    public function test_update_can_edit_content_and_change_status_together(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $task = $project->tasks()->create(['title' => 'A', 'status' => 'new', 'position' => 0]);

        $this->actingAs($user)
            ->patch("/tasks/{$task->id}", [
                'status' => 'ready_for_planning',
                'priority' => 'high',
            ])
            ->assertRedirect();

        $fresh = $task->fresh();
        $this->assertSame('ready_for_planning', $fresh->status);
        $this->assertSame('high', $fresh->priority);
    }

    public function test_update_rejects_an_invalid_priority(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $task = $project->tasks()->create(['title' => 'A', 'status' => 'new', 'position' => 0, 'priority' => 'normal']);

        $this->actingAs($user)
            ->patch("/tasks/{$task->id}", ['priority' => 'bogus'])
            ->assertSessionHasErrors('priority');

        $this->assertSame('normal', $task->fresh()->priority);
    }

    public function test_update_is_blocked_for_other_users(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $project = $this->project($owner);
        $task = $project->tasks()->create(['title' => 'A', 'status' => 'new', 'position' => 0]);

        $this->actingAs($intruder)
            ->patch("/tasks/{$task->id}", ['priority' => 'urgent'])
            ->assertForbidden();
    }

    // ── richer create ────────────────────────────────────────────────────────

    public function test_store_accepts_priority_and_due_date(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $this->actingAs($user)
            ->post("/projects/{$project->id}/tasks", [
                'title' => 'Fresh',
                'priority' => 'high',
                'due_date' => '2026-08-15',
            ])
            ->assertRedirect();

        $task = $project->tasks()->where('title', 'Fresh')->first();
        $this->assertNotNull($task);
        $this->assertSame('high', $task->priority);
        $this->assertSame('2026-08-15', $task->due_date->format('Y-m-d'));
    }

    public function test_store_defaults_priority_to_normal(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $this->actingAs($user)
            ->post("/projects/{$project->id}/tasks", ['title' => 'Plain'])
            ->assertRedirect();

        $this->assertSame('normal', $project->tasks()->where('title', 'Plain')->first()->priority);
    }

    // ── the board still renders ──────────────────────────────────────────────

    public function test_board_renders_for_owner(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $project->tasks()->create(['title' => 'Visible card', 'status' => 'new', 'position' => 0, 'priority' => 'urgent']);

        $this->actingAs($user)
            ->get("/projects/{$project->id}")
            ->assertOk()
            ->assertSee('Visible card')
            ->assertSee('Timeline')
            // The 5-up board must gate on a STOCK Tailwind breakpoint so it actually
            // compiles and ships. lg (1024px) guarantees 5 columns at ~1270–1278px
            // (a 2K monitor split in half). A custom @theme breakpoint silently
            // failed to compile here, leaving the gate stuck at the stock xl (1280px).
            ->assertSee('lg:grid-cols-5', false);
    }

    // ── gantt / timeline ─────────────────────────────────────────────────────

    public function test_gantt_renders_for_owner(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $project->tasks()->create([
            'title' => 'Timeline card',
            'status' => 'developing',
            'position' => 0,
            'start_date' => '2026-06-01',
            'due_date' => '2026-06-10',
        ]);

        $this->actingAs($user)
            ->get("/projects/{$project->id}/gantt")
            ->assertOk()
            ->assertSee('Timeline')
            ->assertSee('Timeline card');
    }

    public function test_gantt_renders_with_no_tasks(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $this->actingAs($user)
            ->get("/projects/{$project->id}/gantt")
            ->assertOk();
    }

    public function test_gantt_is_forbidden_for_a_stranger(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = $this->project($owner);

        $this->actingAs($stranger)
            ->get("/projects/{$project->id}/gantt")
            ->assertForbidden();
    }

    // ── project list overhaul ────────────────────────────────────────────────

    public function test_project_index_renders_with_summaries(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $project->tasks()->create(['title' => 'Done one', 'status' => 'done', 'position' => 0]);
        $project->tasks()->create(['title' => 'Open one', 'status' => 'new', 'position' => 0]);

        $this->actingAs($user)
            ->get('/projects')
            ->assertOk()
            ->assertSee('Demo')
            ->assertSee('done');
    }
}

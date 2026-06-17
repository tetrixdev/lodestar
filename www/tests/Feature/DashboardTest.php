<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $owner): Project
    {
        return $owner->projects()->create(['name' => 'Demo', 'slug' => 'demo-'.uniqid()]);
    }

    public function test_dashboard_renders_for_an_empty_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Inbox zero');
    }

    public function test_dashboard_shows_needs_you_review_and_due_soon(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $needsYou = $project->tasks()->create([
            'title' => 'Approve the plan',
            'status' => Task::STATUS_PLAN_REVIEW,
            'position' => 0,
        ]);

        $dueSoon = $project->tasks()->create([
            'title' => 'Ship it soon',
            'status' => Task::STATUS_READY_FOR_DEV,
            'position' => 1,
            'due_date' => now()->addDays(2)->toDateString(),
        ]);

        $working = $project->tasks()->create([
            'title' => 'Agent is coding',
            'status' => Task::STATUS_DEVELOPING,
            'position' => 2,
        ]);

        $review = $project->reviews()->create([
            'title' => 'Review the change',
            'status' => 'in_review',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Approve the plan')
            ->assertSee('Ship it soon')
            ->assertSee('Agent is coding')
            ->assertSee('Review the change');
    }

    public function test_dashboard_shows_recent_work_sessions(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $project->workSessions()->create(['title' => 'A logged session', 'slug' => 'a-logged']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('A logged session');
    }

    public function test_dashboard_excludes_other_users_data(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        $strangerProject = $this->project($stranger);
        $strangerProject->tasks()->create([
            'title' => 'Strangers secret task',
            'status' => Task::STATUS_HUMAN_REVIEW,
            'position' => 0,
        ]);
        $strangerProject->reviews()->create([
            'title' => 'Strangers secret review',
            'status' => 'in_review',
        ]);
        $strangerProject->workSessions()->create(['title' => 'Strangers session', 'slug' => 's']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Strangers secret task')
            ->assertDontSee('Strangers secret review')
            ->assertDontSee('Strangers session');
    }

    public function test_dashboard_requires_auth(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }
}

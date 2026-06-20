<?php

namespace Tests\Feature;

use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeliverableFlowTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $user, string $name): Project
    {
        return $user->projects()->create(['name' => $name, 'slug' => Str::slug($name).'-'.uniqid()]);
    }

    public function test_board_shows_deliverables_and_standalone_tasks_across_projects(): void
    {
        $user = User::factory()->create();
        $a = $this->project($user, 'Alpha');
        $b = $this->project($user, 'Bravo');

        $deliverable = $a->deliverables()->create(['title' => 'Ship v1', 'status' => Deliverable::STATUS_BUILDING]);
        $deliverable->tasks()->create(['project_id' => $a->id, 'title' => 'Child task one', 'status' => Task::STATUS_DEVELOPING, 'position' => 0]);
        $b->tasks()->create(['title' => 'Standalone hotfix', 'status' => Task::STATUS_NEW, 'position' => 0]);

        $this->actingAs($user)->get(route('board'))
            ->assertOk()
            ->assertSee('Ship v1')
            ->assertSee('Child task one')      // inside the deliverable card
            ->assertSee('Standalone hotfix');  // its own card from another project
    }

    public function test_project_filter_narrows_to_one_project(): void
    {
        $user = User::factory()->create();
        $a = $this->project($user, 'Alpha');
        $b = $this->project($user, 'Bravo');
        $a->deliverables()->create(['title' => 'Alpha deliverable', 'status' => Deliverable::STATUS_NEW]);
        $b->deliverables()->create(['title' => 'Bravo deliverable', 'status' => Deliverable::STATUS_NEW]);

        $this->actingAs($user)->get(route('board', ['project' => $a->id]))
            ->assertOk()
            ->assertSee('Alpha deliverable')
            ->assertDontSee('Bravo deliverable');
    }

    public function test_create_deliverable_redirects_to_show(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user, 'Alpha');

        $this->actingAs($user)
            ->post(route('deliverables.store', $project), ['title' => 'New deliverable'])
            ->assertRedirect();

        $this->assertDatabaseHas('deliverables', ['project_id' => $project->id, 'title' => 'New deliverable', 'status' => 'new']);
    }

    public function test_illegal_lifecycle_move_is_rejected(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user, 'Alpha');
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_NEW]);

        $this->actingAs($user)->from(route('deliverables.show', $d))
            ->patch(route('deliverables.move', $d), ['status' => Deliverable::STATUS_DONE])
            ->assertSessionHasErrors('status');

        $this->assertSame(Deliverable::STATUS_NEW, $d->fresh()->status);
    }

    public function test_plan_approval_is_blocked_until_questions_answered(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user, 'Alpha');
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_PLAN_REVIEW]);
        $q = $d->questions()->create(['question' => 'Which provider?', 'position' => 0]);

        // Blocked while unanswered.
        $this->actingAs($user)->from(route('deliverables.show', $d))
            ->patch(route('deliverables.move', $d), ['status' => Deliverable::STATUS_BUILDING])
            ->assertSessionHasErrors('status');
        $this->assertSame(Deliverable::STATUS_PLAN_REVIEW, $d->fresh()->status);

        // Answer it, then approval succeeds and the branch is stamped.
        $this->actingAs($user)
            ->patch(route('deliverables.questions.answer', [$d, $q->id]), ['answer' => 'Sanctum'])
            ->assertRedirect();

        $this->actingAs($user)
            ->patch(route('deliverables.move', $d), ['status' => Deliverable::STATUS_BUILDING])
            ->assertRedirect();

        $fresh = $d->fresh();
        $this->assertSame(Deliverable::STATUS_BUILDING, $fresh->status);
        $this->assertNotNull($fresh->branch);
        $this->assertSame('main', $fresh->base_branch);
    }

    public function test_dashboard_redirects_to_board(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('board'));
    }

    public function test_needs_you_strip_reflects_cross_project_signals(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user, 'Alpha');

        $project->tasks()->create(['title' => 'Approve me', 'status' => Task::STATUS_PLAN_REVIEW, 'position' => 0]);
        $project->tasks()->create(['title' => 'Review me', 'status' => Task::STATUS_HUMAN_REVIEW, 'position' => 1]);
        $project->tasks()->create(['title' => 'Late', 'status' => Task::STATUS_READY_FOR_DEV, 'position' => 2, 'due_date' => now()->subDay()->toDateString()]);

        $this->actingAs($user)->get(route('board'))
            ->assertOk()
            ->assertSee('plan(s) to approve')
            ->assertSee('review(s) waiting')
            ->assertSee('overdue');
    }

    public function test_board_excludes_other_users_data(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $strangerProject = $this->project($stranger, 'Secret');
        $strangerProject->deliverables()->create(['title' => 'Strangers deliverable', 'status' => Deliverable::STATUS_NEW]);
        $strangerProject->tasks()->create(['title' => 'Strangers task', 'status' => Task::STATUS_NEW, 'position' => 0]);

        $this->actingAs($user)->get(route('board'))
            ->assertOk()
            ->assertDontSee('Strangers deliverable')
            ->assertDontSee('Strangers task');
    }

    public function test_stranger_cannot_view_anothers_deliverable(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $project = $this->project($owner, 'Alpha');
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_NEW]);

        $this->actingAs($stranger)->get(route('deliverables.show', $d))->assertForbidden();
    }
}

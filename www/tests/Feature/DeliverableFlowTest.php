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

    public function test_board_shows_deliverables_and_their_child_tasks_across_projects(): void
    {
        $user = User::factory()->create();
        $a = $this->project($user, 'Alpha');
        $b = $this->project($user, 'Bravo');

        $deliverable = $a->deliverables()->create(['title' => 'Ship v1', 'status' => Deliverable::STATUS_BUILDING]);
        $deliverable->tasks()->create(['project_id' => $a->id, 'title' => 'Child task one', 'status' => Task::STATUS_DEVELOPING, 'position' => 0]);

        $other = $b->deliverables()->create(['title' => 'Ship v2', 'status' => Deliverable::STATUS_BUILDING]);
        $other->tasks()->create(['project_id' => $b->id, 'title' => 'Hotfix child', 'status' => Task::STATUS_DEVELOPING, 'position' => 0]);

        $this->actingAs($user)->get(route('board'))
            ->assertOk()
            ->assertSee('Ship v1')
            ->assertSee('Child task one')   // inside the deliverable card
            ->assertSee('Ship v2')
            ->assertSee('Hotfix child');    // child of a deliverable in another project
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
            ->post(route('deliverables.store', $project), ['title' => 'New deliverable', 'base_branch' => 'main'])
            ->assertRedirect();

        $this->assertDatabaseHas('deliverables', ['project_id' => $project->id, 'title' => 'New deliverable', 'status' => 'new', 'base_branch' => 'main']);
    }

    public function test_per_task_plan_decision_approve_and_request_changes(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user, 'Alpha');
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);
        $approve = $d->tasks()->create(['project_id' => $project->id, 'title' => 'Approve me', 'status' => Task::STATUS_PLAN_REVIEW, 'position' => 0]);
        $reject = $d->tasks()->create(['project_id' => $project->id, 'title' => 'Send back', 'status' => Task::STATUS_PLAN_REVIEW, 'position' => 1]);

        // Approve → joins the build queue.
        $this->actingAs($user)->patch(route('tasks.plan-decision', $approve), ['decision' => 'approve'])->assertRedirect();
        $this->assertSame(Task::STATUS_READY_FOR_DEV, $approve->fresh()->status);

        // Request changes → back to planning with a note.
        $this->actingAs($user)->patch(route('tasks.plan-decision', $reject), ['decision' => 'changes', 'note' => 'split this'])->assertRedirect();
        $fresh = $reject->fresh();
        $this->assertSame(Task::STATUS_READY_FOR_PLANNING, $fresh->status);
        $this->assertSame('split this', $fresh->rework_notes);
    }

    public function test_plan_review_walkthrough_renders_unapproved_tasks(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user, 'Alpha');
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);
        $d->tasks()->create(['project_id' => $project->id, 'title' => 'Awaiting plan approval', 'status' => Task::STATUS_PLAN_REVIEW, 'position' => 0]);

        $this->actingAs($user)->get(route('deliverables.show', $d))
            ->assertOk()
            ->assertSee('Plan review')
            ->assertSee('Awaiting plan approval')
            ->assertSee('Approve plan');
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
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);

        $d->tasks()->create(['project_id' => $project->id, 'title' => 'Approve me', 'status' => Task::STATUS_PLAN_REVIEW, 'position' => 0]);
        $d->tasks()->create(['project_id' => $project->id, 'title' => 'Review me', 'status' => Task::STATUS_HUMAN_REVIEW, 'position' => 1]);
        $d->tasks()->create(['project_id' => $project->id, 'title' => 'Late', 'status' => Task::STATUS_READY_FOR_DEV, 'position' => 2, 'due_date' => now()->subDay()->toDateString()]);

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
        $strangerDeliverable = $strangerProject->deliverables()->create(['title' => 'Strangers deliverable', 'status' => Deliverable::STATUS_BUILDING]);
        $strangerDeliverable->tasks()->create(['project_id' => $strangerProject->id, 'title' => 'Strangers task', 'status' => Task::STATUS_DEVELOPING, 'position' => 0]);

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

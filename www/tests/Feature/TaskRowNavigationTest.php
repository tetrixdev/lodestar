<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Deliverable;
use App\Models\Project;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clickable task-row navigation (task #100 B): Task::rowTarget() returns the OPEN
 * review URL when the card is in a review state (plan/ai/human), and the task-show
 * URL otherwise; openReviewFor() picks the review by phase. The WHOLE <x-task-row>
 * is a single anchor to that one target (id-chip, title and end-dot all inside it),
 * so the entire row — gaps included — is clickable.
 */
class TaskRowNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function task(string $status = Task::STATUS_READY_FOR_DEV): Task
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p-'.uniqid()]);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);

        return $d->tasks()->create([
            'project_id' => $project->id,
            'title' => 'T',
            'status' => $status,
            'position' => 0,
        ]);
    }

    private function review(Task $task, string $type): Review
    {
        $review = $task->project->reviews()->create([
            'title' => 'R', 'scope' => Review::SCOPE_TASK, 'review_type' => $type, 'status' => 'draft',
        ]);
        $review->tasks()->attach($task->id);

        return $review;
    }

    public function test_non_review_state_targets_task_show(): void
    {
        $task = $this->task(Task::STATUS_DEVELOPING);
        $this->assertSame(route('tasks.show', $task), $task->rowTarget());
        $this->assertNull($task->openReviewFor());
    }

    public function test_plan_review_state_targets_the_plan_review(): void
    {
        $task = $this->task(Task::STATUS_READY_FOR_DEV);
        $task->update(['status' => Task::STATUS_PLAN_REVIEW]); // seeds the plan review
        $review = $task->openReviewFor();

        $this->assertNotNull($review);
        $this->assertSame(Review::TYPE_PLAN, $review->review_type);
        $this->assertSame(route('reviews.show', $review), $task->fresh()->rowTarget());
    }

    public function test_ai_review_targets_code_review_and_human_review_targets_functional(): void
    {
        $aiTask = $this->task(Task::STATUS_AI_REVIEW);
        $code = $this->review($aiTask, Review::TYPE_CODE);
        $this->assertSame(route('reviews.show', $code), $aiTask->fresh()->rowTarget());

        $humanTask = $this->task(Task::STATUS_HUMAN_REVIEW);
        $functional = $this->review($humanTask, Review::TYPE_FUNCTIONAL);
        $this->assertSame(route('reviews.show', $functional), $humanTask->fresh()->rowTarget());
    }

    public function test_falls_back_to_task_show_when_no_matching_review_exists(): void
    {
        // In a review state but with no matching review → task-show, never a 404 link.
        $task = $this->task(Task::STATUS_AI_REVIEW);
        $this->assertSame(route('tasks.show', $task), $task->rowTarget());
    }

    public function test_whole_task_row_is_one_anchor_to_the_target(): void
    {
        $task = $this->task(Task::STATUS_AI_REVIEW);
        $review = $this->review($task, Review::TYPE_CODE);
        $task = $task->fresh();

        $html = \Illuminate\Support\Facades\Blade::render('<x-task-row :task="$task" />', ['task' => $task]);

        $target = route('reviews.show', $review);
        // The whole row is ONE anchor (the row wrapper itself), so the entire row —
        // including the gaps — is the click target. Exactly one href to the target.
        $this->assertSame(1, substr_count($html, 'href="'.$target.'"'));
        // The wrapper IS the link: the rendered fragment starts with <a …>.
        $this->assertStringStartsWith('<a ', trim($html));
        // It carries a hover state, and still shows the id-chip + title.
        $this->assertStringContainsString('hover:bg-', $html);
        $this->assertStringContainsString(sprintf('T%02d', $task->sub_id), $html);
    }
}

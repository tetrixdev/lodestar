<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Deliverable;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The deliverable-level open-questions mechanism is gone (task #100 A): the table,
 * model, relation, routes and UI are removed; questions now live as plan-review
 * findings. The deliverable page instead shows expanded (non-collapsible) plan +
 * functional review sections — and ONLY when an OPEN review of that type exists.
 */
class DeliverableQuestionsRemovedTest extends TestCase
{
    use RefreshDatabase;

    public function test_deliverable_questions_table_is_dropped(): void
    {
        $this->assertFalse(Schema::hasTable('deliverable_questions'));
    }

    public function test_question_model_and_relation_are_gone(): void
    {
        // The model file is deleted (don't class_exists() it — that would try to
        // autoload the removed file).
        $this->assertFileDoesNotExist(app_path('Models/DeliverableQuestion.php'));
        $this->assertFalse(method_exists(Deliverable::class, 'questions'));
        $this->assertFalse(method_exists(Deliverable::class, 'hasUnansweredQuestions'));
    }

    public function test_question_routes_are_gone(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('deliverables.questions.store'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('deliverables.questions.answer'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('tasks.plan-decision'));
    }

    public function test_open_plan_review_renders_expanded_without_a_collapse_toggle(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);
        $task = $d->tasks()->create([
            'project_id' => $project->id,
            'title' => 'Awaiting plan',
            'status' => Task::STATUS_READY_FOR_DEV,
            'position' => 0,
        ]);
        $task->update(['status' => Task::STATUS_PLAN_REVIEW]); // seeds the (open) plan review

        $res = $this->actingAs($user)->get(route('deliverables.show', $d))->assertOk();

        $res->assertSee('Plan review')->assertDontSee('Open questions');
        // The list is rendered inline (the task row is present) and the page carries
        // NO collapse toggle at all — the review sections are always expanded.
        $res->assertSeeText('Awaiting plan');
        $this->assertStringNotContainsString('x-collapse', $res->getContent());
    }

    public function test_concluded_review_does_not_appear_in_the_sections(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p2']);
        $d = $project->deliverables()->create(['title' => 'D', 'status' => Deliverable::STATUS_BUILDING]);
        $task = $d->tasks()->create([
            'project_id' => $project->id,
            'title' => 'Done planning',
            'status' => Task::STATUS_READY_FOR_DEV,
            'position' => 0,
        ]);

        // A CONCLUDED plan review (outcome set, status done) must NOT show in the list.
        $concluded = $project->reviews()->create([
            'title' => 'Plan review: '.$task->title,
            'scope' => \App\Models\Review::SCOPE_TASK,
            'review_type' => \App\Models\Review::TYPE_PLAN,
            'status' => 'done',
            'outcome' => 'approved',
        ]);
        $concluded->tasks()->attach($task->id);

        $this->actingAs($user)->get(route('deliverables.show', $d))
            ->assertOk()
            ->assertDontSee('Plan review —')
            ->assertDontSee('Functional review —');
    }
}

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
 * findings. The deliverable page instead shows collapsible plan + functional
 * review sections.
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

    public function test_deliverable_page_has_collapsible_plan_and_functional_sections(): void
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
        $task->update(['status' => Task::STATUS_PLAN_REVIEW]); // seeds the plan review

        // Attach a functional review so its (collapsed) section renders too.
        $functional = $project->reviews()->create([
            'title' => 'Functional review: '.$task->title,
            'scope' => \App\Models\Review::SCOPE_TASK,
            'review_type' => \App\Models\Review::TYPE_FUNCTIONAL,
            'status' => 'draft',
        ]);
        $functional->tasks()->attach($task->id);

        $this->actingAs($user)->get(route('deliverables.show', $d))
            ->assertOk()
            ->assertSee('Plan review')
            ->assertSee('Functional review')
            ->assertDontSee('Open questions');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskDetailTest extends TestCase
{
    use RefreshDatabase;

    private function makeTask(User $owner, array $attrs = []): Task
    {
        $project = $owner->projects()->create(['name' => 'P', 'slug' => 'p-'.uniqid()]);

        return $project->tasks()->create(array_merge([
            'title' => 'Build the thing',
            'status' => Task::STATUS_NEW,
            'plan' => '## The plan',
        ], $attrs));
    }

    public function test_detail_page_renders_with_title_plan_and_comments(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user);
        $task->comments()->create([
            'user_id' => $user->id,
            'author' => 'Jasper',
            'body' => 'A first note',
        ]);

        $this->actingAs($user)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('Build the thing')
            ->assertSee('The plan')
            ->assertSee('A first note');
    }

    public function test_plan_falls_back_when_null(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, ['plan' => null]);

        $this->actingAs($user)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('No plan yet');
    }

    public function test_posting_a_comment_creates_it_and_an_event(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user);

        $this->actingAs($user)
            ->post(route('tasks.comments.store', $task), ['body' => 'Please use a queue here'])
            ->assertRedirect();

        $this->assertDatabaseHas('task_comments', [
            'task_id' => $task->id,
            'user_id' => $user->id,
            'author' => $user->name,
            'body' => 'Please use a queue here',
        ]);

        $this->assertDatabaseHas('task_events', [
            'task_id' => $task->id,
            'type' => 'commented',
            'actor' => $user->name,
        ]);
    }

    public function test_comment_body_is_required(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user);

        $this->actingAs($user)
            ->post(route('tasks.comments.store', $task), ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('task_comments', 0);
    }

    public function test_stranger_cannot_view_the_task(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $task = $this->makeTask($owner);

        $this->actingAs($stranger)
            ->get(route('tasks.show', $task))
            ->assertForbidden();
    }

    public function test_stranger_cannot_comment_on_the_task(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $task = $this->makeTask($owner);

        $this->actingAs($stranger)
            ->post(route('tasks.comments.store', $task), ['body' => 'sneaky'])
            ->assertForbidden();

        $this->assertDatabaseCount('task_comments', 0);
    }

    public function test_summary_is_shown_by_default_with_a_full_affordance(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, [
            'body' => 'The very long description body.',
            'body_summary' => 'Short scannable abstract.',
        ]);

        $this->actingAs($user)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('Short scannable abstract.')
            ->assertSee('Show full');
    }

    public function test_update_requires_a_summary_when_the_detail_is_filled(): void
    {
        $user = User::factory()->create();
        $task = $this->makeTask($user, ['plan' => null]);

        // Setting body with no body_summary is rejected.
        $this->actingAs($user)
            ->from(route('tasks.show', $task))
            ->patch(route('tasks.update', $task), ['body' => 'A detailed body'])
            ->assertSessionHasErrors('body_summary');

        $this->assertNull($task->fresh()->body);

        // Both halves together save fine.
        $this->actingAs($user)
            ->patch(route('tasks.update', $task), ['body' => 'A detailed body', 'body_summary' => 'TL;DR.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('TL;DR.', $task->fresh()->body_summary);
    }
}

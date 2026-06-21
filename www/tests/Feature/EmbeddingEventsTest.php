<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EmbedObject;
use App\Jobs\ForgetObject;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The model events that drive the index: saving an embeddable queues an
 * EmbedObject, deleting one queues a ForgetObject — both on the dedicated
 * `embeddings` queue. (No vector type needed — this only asserts which jobs are
 * dispatched, so it runs on the default sqlite connection.)
 */
class EmbeddingEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_an_embeddable_queues_an_embed_job_on_the_embeddings_queue(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $project = $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket']);

        Queue::assertPushed(EmbedObject::class, function (EmbedObject $job) use ($project) {
            return $job->type === Project::class
                && $job->id === $project->id
                && $job->queue === 'embeddings';
        });
    }

    public function test_deleting_an_embeddable_queues_a_forget_job(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'Rocket', 'slug' => 'rocket']);
        $task = $this->makeTask($project, ['title' => 'A task']);

        Queue::fake();
        $task->delete();

        Queue::assertPushed(ForgetObject::class, function (ForgetObject $job) use ($task) {
            return $job->type === Task::class
                && $job->id === $task->id
                && $job->queue === 'embeddings';
        });
    }
}

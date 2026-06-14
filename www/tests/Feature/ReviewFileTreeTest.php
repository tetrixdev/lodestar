<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewFileTreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_walkthrough_renders_the_changed_file_tree_with_coverage(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $review = $project->reviews()->create(['title' => 'R', 'status' => 'draft']);

        $covered = $review->files()->create(['path' => 'app/Covered.php', 'status' => 'modified', 'position' => 0]);
        $review->files()->create(['path' => 'app/Uncovered.php', 'status' => 'added', 'position' => 1]);

        $section = $review->sections()->create(['title' => 'S', 'mode' => 'direct', 'status' => 'open', 'position' => 1]);
        $section->files()->attach($covered);

        $this->actingAs($user)->get(route('reviews.show', $review))
            ->assertOk()
            ->assertSee('Changed files')
            ->assertSee('app/Covered.php')
            ->assertSee('app/Uncovered.php')
            ->assertSee('1 uncovered'); // the guard surfaces the gap in the UI
    }
}

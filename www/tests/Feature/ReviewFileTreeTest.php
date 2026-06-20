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

    public function test_the_walkthrough_renders_the_manual_test_checklist(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $review = $project->reviews()->create(['title' => 'R', 'status' => 'in_review']);
        $review->claimFor($user->id);

        $review->sections()->create([
            'title' => 'S', 'mode' => 'direct', 'status' => 'open', 'position' => 1,
            'checks' => ['log in as admin', 'create a card'],
            'checked' => [1], // second item already ticked
        ]);

        $body = $this->actingAs($user)->get(route('reviews.show', $review))
            ->assertOk()
            ->assertSee('Manual tests')
            ->assertSee('log in as admin')
            ->assertSee('create a card')
            ->getContent();

        // The render seeds the Alpine `section()` component with the persisted tick
        // state and the walkthrough with the aggregate manual-test totals. The view
        // emits these via @js (Js::from), so build the expected escaped payloads the
        // same way: the full checklist (item 1 'create a card' done) and the summary
        // (2 items, 1 done).
        $section = $review->sections()->first();
        $this->assertStringContainsString(
            (string) \Illuminate\Support\Js::from($section->checklist()),
            $body,
        );
        $this->assertStringContainsString(
            (string) \Illuminate\Support\Js::from($review->load('sections')->manualTestSummary()),
            $body,
        );
        // Sanity: the persisted tick is reflected in the seeded checklist.
        $this->assertTrue($section->checklist()[1]['done']);
    }

    public function test_sections_dispatch_the_file_viewer_with_full_payload(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $review = $project->reviews()->create(['title' => 'R', 'status' => 'draft']);

        $covered = $review->files()->create([
            'path' => 'app/Service.php', 'status' => 'modified', 'position' => 0,
            'additions' => 7, 'deletions' => 2,
        ]);

        $section = $review->sections()->create(['title' => 'S', 'mode' => 'direct', 'status' => 'open', 'position' => 1]);
        $section->files()->attach($covered);

        $html = $this->actingAs($user)->get(route('reviews.show', $review))
            ->assertOk()
            ->assertSee('Files in this section')
            ->getContent();

        // The section's file ref emits an open-file dispatch carrying the full
        // payload (id/path/status/additions/deletions/markdown), same as the tree.
        // Js::from encodes quotes as "; decode that (and HTML entities) first.
        $decoded = str_replace('\u0022', '"', html_entity_decode($html));
        $this->assertStringContainsString('open-file', $html);
        $this->assertStringContainsString('"id":'.$covered->id, $decoded);
        $this->assertStringContainsString('"additions":7', $decoded);
        $this->assertStringContainsString('"deletions":2', $decoded);
        $this->assertStringContainsString('"status":"modified"', $decoded);
    }

    public function test_markdown_file_carries_the_markdown_flag(): void
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p']);
        $review = $project->reviews()->create(['title' => 'R', 'status' => 'draft']);
        $review->files()->create(['path' => 'docs/README.md', 'status' => 'modified', 'position' => 0]);

        $html = $this->actingAs($user)->get(route('reviews.show', $review))
            ->assertOk()->getContent();

        // The open-file payload flags markdown so the modal defaults to rich diff.
        $decoded = str_replace('\u0022', '"', html_entity_decode($html));
        $this->assertStringContainsString('"markdown":true', $decoded);
    }
}

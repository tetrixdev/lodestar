<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The out-of-MCP attachment endpoint (task #100 #7): an agent fetches a human's
 * review attachment with its `agent`-ability Bearer token. Project-access gated,
 * served as a forced download with nosniff. get_review also surfaces attachments.
 */
class ReviewAttachmentApiTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'review-attachments';

    private function attachment(User $owner): \App\Models\ReviewAttachment
    {
        $project = $owner->projects()->create(['name' => 'P', 'slug' => 'p-'.uniqid()]);
        $review = $project->reviews()->create([
            'title' => 'R', 'scope' => Review::SCOPE_TASK,
            'review_type' => Review::TYPE_FUNCTIONAL, 'status' => 'draft',
            'assigned_to_user_id' => $owner->id,
        ]);
        $section = $review->sections()->create(['title' => 'S', 'mode' => 'direct', 'status' => 'open', 'position' => 0]);

        $this->actingAs($owner)->post(
            route('reviews.sections.attachments.store', [$review, $section]),
            ['file' => UploadedFile::fake()->create('shot.png', 12, 'image/png')],
        )->assertOk();

        return $section->attachments()->firstOrFail();
    }

    public function test_authorized_token_gets_the_file_as_a_download(): void
    {
        Storage::fake(self::DISK);
        $owner = User::factory()->create();
        $attachment = $this->attachment($owner);

        Sanctum::actingAs($owner, ['agent']);
        $res = $this->get(route('api.review-attachments.show', $attachment))->assertOk();

        $this->assertStringStartsWith('attachment', $res->headers->get('content-disposition'));
        $this->assertSame('nosniff', $res->headers->get('x-content-type-options'));
        $this->assertSame('image/png', $res->headers->get('content-type'));
    }

    public function test_a_stranger_token_is_forbidden(): void
    {
        Storage::fake(self::DISK);
        $owner = User::factory()->create();
        $attachment = $this->attachment($owner);

        Sanctum::actingAs(User::factory()->create(), ['agent']);
        $this->get(route('api.review-attachments.show', $attachment))->assertForbidden();
    }

    public function test_get_review_surfaces_section_attachments(): void
    {
        Storage::fake(self::DISK);
        $owner = User::factory()->create();
        $attachment = $this->attachment($owner);
        $review = $attachment->section->review;

        \App\Mcp\Servers\LodestarServer::actingAs($owner)
            ->tool(\App\Mcp\Tools\GetReviewTool::class, ['review_id' => $review->id])
            ->assertOk()
            ->assertSee('shot.png')
            ->assertSee('/api/review-attachments/'.$attachment->id);
    }
}

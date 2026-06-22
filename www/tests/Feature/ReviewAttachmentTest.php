<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Review section attachments (task #100 C): the holder can upload images/files to
 * a section, they're stored on the PRIVATE review-attachments disk, listed on the
 * section, deletable before send; download is access-gated; non-holders cannot
 * upload/delete; deleting a section cascades its attachments + their files away.
 */
class ReviewAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'review-attachments';

    private function setup_review(): array
    {
        $user = User::factory()->create();
        $project = $user->projects()->create(['name' => 'P', 'slug' => 'p-'.uniqid()]);
        $review = $project->reviews()->create([
            'title' => 'R', 'scope' => Review::SCOPE_TASK,
            'review_type' => Review::TYPE_FUNCTIONAL, 'status' => 'draft',
            'assigned_to_user_id' => $user->id,
        ]);
        $section = $review->sections()->create(['title' => 'S', 'mode' => 'direct', 'status' => 'open', 'position' => 0]);

        return [$user, $project, $review, $section];
    }

    public function test_holder_can_upload_an_image_and_a_file(): void
    {
        Storage::fake(self::DISK);
        [$user, , $review, $section] = $this->setup_review();

        $this->actingAs($user)->post(
            route('reviews.sections.attachments.store', [$review, $section]),
            ['file' => UploadedFile::fake()->create('shot.png', 12, 'image/png')],
        )->assertOk()->assertJsonPath('attachment.is_image', true);

        $this->actingAs($user)->post(
            route('reviews.sections.attachments.store', [$review, $section]),
            ['file' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')],
        )->assertOk()->assertJsonPath('attachment.is_image', false);

        $this->assertSame(2, $section->attachments()->count());
        foreach ($section->attachments as $a) {
            $this->assertSame(self::DISK, $a->disk);
            Storage::disk(self::DISK)->assertExists($a->path);
        }
    }

    public function test_non_holder_cannot_upload(): void
    {
        Storage::fake(self::DISK);
        [, , $review, $section] = $this->setup_review();
        // A user who does not hold the review (here, a stranger) cannot upload.
        $other = User::factory()->create();
        $this->actingAs($other)->post(
            route('reviews.sections.attachments.store', [$review, $section]),
            ['file' => UploadedFile::fake()->create('x.png', 12, 'image/png')],
        )->assertForbidden();

        $this->assertSame(0, $section->attachments()->count());
    }

    public function test_delete_removes_the_file(): void
    {
        Storage::fake(self::DISK);
        [$user, , $review, $section] = $this->setup_review();

        $this->actingAs($user)->post(
            route('reviews.sections.attachments.store', [$review, $section]),
            ['file' => UploadedFile::fake()->create('shot.png', 12, 'image/png')],
        )->assertOk();
        $attachment = $section->attachments()->firstOrFail();
        Storage::disk(self::DISK)->assertExists($attachment->path);

        $this->actingAs($user)->delete(
            route('reviews.sections.attachments.destroy', [$review, $section, $attachment]),
        )->assertOk();

        $this->assertSame(0, $section->attachments()->count());
        Storage::disk(self::DISK)->assertMissing($attachment->path);
    }

    public function test_download_is_access_gated(): void
    {
        Storage::fake(self::DISK);
        [$user, , $review, $section] = $this->setup_review();
        $this->actingAs($user)->post(
            route('reviews.sections.attachments.store', [$review, $section]),
            ['file' => UploadedFile::fake()->create('notes.pdf', 5, 'application/pdf')],
        )->assertOk();
        $attachment = $section->attachments()->firstOrFail();

        // The holder can download.
        $this->actingAs($user)->get(
            route('reviews.sections.attachments.download', [$review, $section, $attachment]),
        )->assertOk();

        // A stranger (no project access) cannot.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get(
            route('reviews.sections.attachments.download', [$review, $section, $attachment]),
        )->assertForbidden();
    }

    public function test_download_is_always_attachment_with_nosniff(): void
    {
        Storage::fake(self::DISK);
        [$user, , $review, $section] = $this->setup_review();
        // Even an SVG (the historical stored-XSS vector) is served as a forced
        // download with nosniff and a safe content-type derived from the extension.
        $this->actingAs($user)->post(
            route('reviews.sections.attachments.store', [$review, $section]),
            ['file' => UploadedFile::fake()->create('diagram.svg', 5, 'image/svg+xml')],
        )->assertOk();
        $attachment = $section->attachments()->firstOrFail();

        $res = $this->actingAs($user)->get(
            route('reviews.sections.attachments.download', [$review, $section, $attachment]),
        )->assertOk();

        $this->assertStringStartsWith('attachment', $res->headers->get('content-disposition'));
        $this->assertSame('nosniff', $res->headers->get('x-content-type-options'));
        $this->assertSame('image/svg+xml', $res->headers->get('content-type'));
    }

    public function test_office_documents_upload_ok_and_executables_are_rejected(): void
    {
        Storage::fake(self::DISK);
        [$user, , $review, $section] = $this->setup_review();

        // docx / xlsx upload fine under the permissive blacklist.
        foreach (['report.docx', 'data.xlsx'] as $name) {
            $this->actingAs($user)->post(
                route('reviews.sections.attachments.store', [$review, $section]),
                ['file' => UploadedFile::fake()->create($name, 20)],
            )->assertOk();
        }

        // An .exe is blocked.
        $this->actingAs($user)->post(
            route('reviews.sections.attachments.store', [$review, $section]),
            ['file' => UploadedFile::fake()->create('virus.exe', 10)],
        )->assertSessionHasErrors('file');

        $this->assertSame(2, $section->attachments()->count());
    }

    public function test_deleting_a_section_cascades_attachments_and_files(): void
    {
        Storage::fake(self::DISK);
        [$user, , $review, $section] = $this->setup_review();
        $this->actingAs($user)->post(
            route('reviews.sections.attachments.store', [$review, $section]),
            ['file' => UploadedFile::fake()->create('shot.png', 12, 'image/png')],
        )->assertOk();
        $attachment = $section->attachments()->firstOrFail();
        $path = $attachment->path;

        // Delete each attachment via the model so its file-removal hook fires, then
        // the section (DB cascade handles the rows; the hook handles the blobs).
        $section->attachments->each->delete();
        $section->delete();

        $this->assertDatabaseMissing('review_attachments', ['id' => $attachment->id]);
        Storage::disk(self::DISK)->assertMissing($path);
    }
}

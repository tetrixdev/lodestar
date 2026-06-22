<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ReviewAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The out-of-MCP attachment endpoint (task #100 #7): an agent fetches a human's
 * review attachment with its Bearer token (same Sanctum `agent` ability as the
 * /api/projects/{slug}/secrets + /tools endpoints), so the file bytes never enter
 * the MCP/LLM channel. Access is gated on the token user being able to reach the
 * attachment's project (via its section → review → project). Served as a forced
 * download with nosniff and a Content-Type derived from the validated extension,
 * exactly like the web download — so even an SVG can't execute in any origin.
 */
class ReviewAttachmentController extends Controller
{
    public function show(Request $request, ReviewAttachment $attachment): StreamedResponse
    {
        // The attachment is reachable only if the token's user can access the
        // project that owns its review.
        $project = $attachment->section?->review?->project;
        abort_if($project === null, 404);
        abort_unless($project->isAccessibleBy($request->user()), 403);

        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->safeContentType(),
            'X-Content-Type-Options' => 'nosniff',
        ], 'attachment');
    }
}

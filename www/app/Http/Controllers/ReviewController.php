<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReviewController extends Controller
{
    /** A project's reviews. */
    public function index(Request $request, Project $project): View
    {
        abort_unless($project->user_id === $request->user()->id, 403);

        $reviews = $project->reviews()->latest()->withCount('sections')->get();

        return view('reviews.index', ['project' => $project, 'reviews' => $reviews]);
    }

    /** The review walkthrough — ordered sections, rebuilt top-to-bottom. */
    public function show(Request $request, Review $review): View
    {
        abort_unless($review->project->user_id === $request->user()->id, 403);

        $review->load(['sections', 'project', 'assignee', 'tasks' => fn ($q) => $q->orderBy('title')]);

        return view('reviews.show', ['review' => $review]);
    }

    /** Persist a section's sign-off / comment (called from the walkthrough via fetch). */
    public function updateSection(Request $request, Review $review, ReviewSection $section): JsonResponse
    {
        abort_unless($review->project->user_id === $request->user()->id, 403);
        abort_unless($section->review_id === $review->id, 404);

        // Sign-off is gated on assignment: only the human currently holding the
        // review may sign off or comment on its sections.
        abort_unless($review->assigned_to_user_id === $request->user()->id, 403,
            'Assign this review to yourself before signing off its sections.');

        $data = $request->validate([
            'status' => ['nullable', 'in:open,signed_off'],
            'note' => ['nullable', 'string', 'max:4000'],
        ]);

        $section->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json([
            'ok' => true,
            'signed_off' => $review->sections()->where('status', 'signed_off')->count(),
            'total' => $review->sections()->count(),
        ]);
    }

    /** Atomically self-assign this review (succeeds only if currently unassigned). */
    public function assign(Request $request, Review $review): RedirectResponse
    {
        abort_unless($review->project->user_id === $request->user()->id, 403);

        if (! $review->claimFor($request->user()->id)) {
            $holder = $review->fresh('assignee')->assignee?->name ?? 'someone else';

            return redirect()->route('reviews.show', $review)
                ->with('status', "Already being reviewed by {$holder}.");
        }

        return redirect()->route('reviews.show', $review);
    }

    /** Release this review (only if the requester currently holds it). */
    public function unassign(Request $request, Review $review): RedirectResponse
    {
        abort_unless($review->project->user_id === $request->user()->id, 403);

        $review->releaseFor($request->user()->id);

        return redirect()->route('reviews.show', $review);
    }
}

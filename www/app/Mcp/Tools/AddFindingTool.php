<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\ReviewFinding;
use App\Models\ReviewSection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Description('Raise one finding within a review section — a realistic scenario + impact (not a line nitpick), with a severity. The human triages it (approve / dismiss / must_fix) in the web UI. On a PLAN review a finding is an OPEN QUESTION the human must answer (raise it on the Client-facing or Technical-architecture section); those compile into the rework brief if the plan is returned to planning. severity is one of: info, minor, major, critical.')]
#[Name('add_finding')]
class AddFindingTool extends LodestarTool
{
    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'section_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'detail' => ['nullable', 'string'],
            'severity' => ['nullable', 'string', 'in:'.implode(',', ReviewFinding::SEVERITIES)],
        ]);

        // Resolve the section via an owned review so tenancy is enforced exactly
        // as the section upsert does it.
        $section = ReviewSection::query()
            ->whereHas('review.project', fn ($q) => $q->accessibleBy($this->currentUser($request)))
            ->with('review')
            ->whereKey((int) $data['section_id'])
            ->first();

        if (! $section) {
            return Response::error('No section with that id belongs to you.');
        }

        $review = $section->review;

        // Same freeze rule as upsert_review_section: once a review is handed to a
        // human (in_review / done) its findings are frozen — only draft reviews
        // can still be added to by the agent.
        if ($review->status !== 'draft') {
            return Response::error("Review #{$review->id} is locked (status: {$review->status}); it has been handed off and can no longer be edited.");
        }

        $finding = $section->findings()->create([
            'title' => $data['title'],
            'detail' => $data['detail'] ?? null,
            'severity' => $data['severity'] ?? 'minor',
            'status' => 'open',
            'position' => (int) $section->findings()->max('position') + 1,
        ]);

        return Response::json([
            'id' => $finding->id,
            'section_id' => $section->id,
            'severity' => $finding->severity,
            'finding_count' => $section->findings()->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'section_id' => $schema->integer()->description('The review section to raise the finding in.')->required(),
            'title' => $schema->string()->description('Short title of the concern.')->required(),
            'detail' => $schema->string()->description('The realistic scenario + impact (markdown).'),
            'severity' => $schema->string()->enum(ReviewFinding::SEVERITIES)->description('Severity (default minor).'),
        ];
    }
}

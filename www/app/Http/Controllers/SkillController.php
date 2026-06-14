<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Skill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The skills settings page. System skills (ours) are visible and read-only; a
 * user can **duplicate** one into an editable fork and edit it. Choosing which
 * skill a phase actually runs (binding) is a later task — for now a fork simply
 * exists alongside the system original.
 */
class SkillController extends Controller
{
    public function index(Request $request): View
    {
        return view('settings.skills', [
            'phases' => Skill::PHASES,
            'phaseLabels' => Skill::PHASE_LABELS,
            // The current system skill per phase (read-only).
            'systemSkills' => collect(Skill::PHASES)
                ->mapWithKeys(fn ($phase) => [$phase => Skill::currentSystem($phase)]),
            // This user's editable forks.
            'forks' => $request->user()->skills()->where('kind', Skill::KIND_USER)->latest()->get(),
        ]);
    }

    /** Clone a system skill into an editable fork the user owns. */
    public function duplicate(Request $request, Skill $skill): RedirectResponse
    {
        abort_unless($skill->isSystem(), 403, 'Only system skills can be duplicated.');

        $fork = $request->user()->skills()->create([
            'kind' => Skill::KIND_USER,
            'key' => $skill->key,
            'version' => 1,
            'title' => $skill->title.' (my copy)',
            'body' => $skill->body,
            'source_version' => $skill->version,
        ]);

        return redirect()->route('skills.edit', $fork);
    }

    public function edit(Request $request, Skill $skill): View
    {
        $this->authorizeFork($request, $skill);

        return view('settings.skill-edit', ['skill' => $skill]);
    }

    public function update(Request $request, Skill $skill): RedirectResponse
    {
        $this->authorizeFork($request, $skill);

        $skill->update($request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]));

        return redirect()->route('skills.index')->with('status', 'skill-saved');
    }

    public function destroy(Request $request, Skill $skill): RedirectResponse
    {
        $this->authorizeFork($request, $skill);
        $skill->delete();

        return redirect()->route('skills.index')->with('status', 'skill-deleted');
    }

    /** A user may only edit/delete their own user (fork) skills, never a system skill. */
    private function authorizeFork(Request $request, Skill $skill): void
    {
        abort_unless(
            ! $skill->isSystem() && $skill->user_id === $request->user()->id,
            403,
        );
    }
}

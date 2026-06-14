<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Skill;
use App\Models\SkillBinding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The skills settings page. System skills (ours) are visible and read-only; a
 * user can **duplicate** one into an editable fork and edit it, then **bind**
 * each loop phase to run either the current system skill or one of their forks
 * (the user default binding, project_id = null). No binding = the system skill.
 */
class SkillController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $forks = $user->skills()->where('kind', Skill::KIND_USER)->latest()->get();

        return view('settings.skills', [
            'phases' => Skill::PHASES,
            'phaseLabels' => Skill::PHASE_LABELS,
            // The current system skill per phase (read-only).
            'systemSkills' => collect(Skill::PHASES)
                ->mapWithKeys(fn ($phase) => [$phase => Skill::currentSystem($phase)]),
            // This user's editable forks.
            'forks' => $forks,
            // Forks grouped by phase key, for the per-phase bind picker.
            'forksByPhase' => $forks->groupBy('key'),
            // The user's default binding per phase (project_id null), keyed by phase.
            'bindings' => SkillBinding::query()
                ->where('user_id', $user->id)
                ->whereNull('project_id')
                ->get()
                ->keyBy('phase'),
            // The skill the loop currently runs per phase (binding → else system).
            'effective' => collect(Skill::PHASES)
                ->mapWithKeys(fn ($phase) => [$phase => Skill::resolve($user, null, $phase)]),
        ]);
    }

    /** Bind a phase (user default, project_id null) to a fork the user owns or the current system skill. */
    public function bind(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'phase' => ['required', 'string', 'in:'.implode(',', Skill::PHASES)],
            'skill_id' => ['required', 'integer'],
        ]);

        $skill = Skill::findOrFail($data['skill_id']);

        // The target must be for this phase, and either the user's own fork or
        // the current system skill for that phase.
        $isOwnFork = ! $skill->isSystem() && $skill->user_id === $user->id;
        $isCurrentSystem = $skill->isSystem()
            && Skill::currentSystem($data['phase'])?->is($skill);

        abort_unless(
            $skill->key === $data['phase'] && ($isOwnFork || $isCurrentSystem),
            403,
            'That skill cannot be bound to this phase.',
        );

        SkillBinding::updateOrCreate(
            ['user_id' => $user->id, 'project_id' => null, 'phase' => $data['phase']],
            ['skill_id' => $skill->id],
        );

        return redirect()->route('skills.index')->with('status', 'skill-bound');
    }

    /** Remove the user's default binding for a phase (fall back to the system skill). */
    public function unbind(Request $request, string $phase): RedirectResponse
    {
        abort_unless(in_array($phase, Skill::PHASES, true), 404);

        SkillBinding::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('project_id')
            ->where('phase', $phase)
            ->delete();

        return redirect()->route('skills.index')->with('status', 'skill-unbound');
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Skill;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The skills page. Skills are now layered: a phase prompt is composed across
 * scopes (system → team → personal → project). This page shows the EFFECTIVE
 * composed prompt for each phase as your loop would receive it, plus which
 * layers contributed. Authoring (propose/approve, versioning, the overwrite
 * toggle, named skills) lands in the filterable overview — see task #53 P4.
 */
class SkillController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        // Composed without a project: the system base + this user's personal layer.
        $composed = collect(Skill::PHASES)->mapWithKeys(
            fn (string $phase) => [$phase => Skill::compose($user, null, $phase)],
        );

        return view('settings.skills', [
            'phases' => Skill::PHASES,
            'phaseLabels' => Skill::PHASE_LABELS,
            'composed' => $composed,
        ]);
    }
}

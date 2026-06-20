<?php

declare(strict_types=1);

use App\Models\Deliverable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split the OVERLOADED `base_branch` into two clean concerns:
 *  - `base_branch`  — the MERGE TARGET (what `branch` merges into; a real branch).
 *  - `comparison_ref` (NEW) — the REVIEW DIFF-BASE (what reviews diff `branch`
 *    against; may be a branch OR a tag / whole-app baseline).
 *
 * Until now `base_branch` was used as BOTH, with a prose hack in the merge
 * playbook for the tag case (v0.5 → `baseline-laravel`). We add `comparison_ref`
 * and backfill it from `base_branch`, which exactly PRESERVES current review
 * behaviour (reviews still diff the same ref). After this the two are free to
 * diverge: merges go `branch → base_branch`; reviews diff `comparison_ref … branch`.
 *
 * Backfill then tighten to NOT NULL (the model defaults it to base_branch on create).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliverables', function (Blueprint $table) {
            $table->string('comparison_ref')->nullable()->after('base_branch'); // review diff-base
        });

        // Preserve current review behaviour: existing rows diffed against base_branch.
        Deliverable::withTrashed()->whereNull('comparison_ref')->update([
            'comparison_ref' => DB::raw('base_branch'),
        ]);

        // Belt-and-braces for any legacy row still missing both.
        Deliverable::withTrashed()->whereNull('comparison_ref')->update(['comparison_ref' => 'main']);

        Schema::table('deliverables', function (Blueprint $table) {
            $table->string('comparison_ref')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('deliverables', function (Blueprint $table) {
            $table->dropColumn('comparison_ref');
        });
    }
};

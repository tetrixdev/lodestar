<?php

declare(strict_types=1);

use App\Models\Deliverable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `branch` and `base_branch` are now set when a deliverable is CREATED, not lazily
 * when it enters building — `branch` defaults to its `branchName()` (D{id:06d}-slug)
 * and `base_branch` is required input (default `main`). Both are therefore always
 * present, so make the columns NOT NULL.
 *
 * Backfill guard: stamp any legacy row that somehow lacks them before tightening,
 * so the migration is safe to run on existing data (both live rows are already
 * populated; this is belt-and-braces).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Belt-and-braces backfill so the NOT NULL change can never fail on legacy data.
        Deliverable::withTrashed()->whereNull('base_branch')->update(['base_branch' => 'main']);
        Deliverable::withTrashed()->whereNull('branch')->get()->each(function (Deliverable $d): void {
            $d->forceFill(['branch' => $d->branchName()])->saveQuietly();
        });

        Schema::table('deliverables', function (Blueprint $table) {
            $table->string('branch')->nullable(false)->change();
            $table->string('base_branch')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('deliverables', function (Blueprint $table) {
            $table->string('branch')->nullable()->change();
            $table->string('base_branch')->nullable()->change();
        });
    }
};

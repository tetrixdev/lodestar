<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deliverable is a SCOPE, not a plan: deliverable-level planning is emergent
 * from its child tasks' plans (and the per-task plan review), so the deliverable
 * itself never carries a plan. Drop the unused plan / plan_summary columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliverables', function (Blueprint $table) {
            $table->dropColumn(['plan', 'plan_summary']);
        });
    }

    public function down(): void
    {
        Schema::table('deliverables', function (Blueprint $table) {
            $table->text('plan')->nullable();
            $table->text('plan_summary')->nullable();
        });
    }
};

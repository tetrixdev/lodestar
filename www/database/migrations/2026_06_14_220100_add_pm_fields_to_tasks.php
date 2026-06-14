<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project-management + phase fields on a task: priority and scheduling (for the
 * board badges, sorting, and the Gantt timeline), the dev branch, the plan
 * artifact, and the rework notes a review hands back to the next dev round.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('priority')->default('normal')->after('category'); // low|normal|high|urgent
            $table->date('start_date')->nullable()->after('priority');
            $table->date('due_date')->nullable()->after('start_date');
            $table->string('branch')->nullable()->after('due_date');          // the dev branch
            $table->text('plan')->nullable()->after('body');                  // the planning artifact (markdown)
            $table->text('rework_notes')->nullable()->after('plan');          // what a review sent back
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['priority', 'start_date', 'due_date', 'branch', 'plan', 'rework_notes']);
        });
    }
};

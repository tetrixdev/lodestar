<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete only: a task or deliverable is never physically removed (decision
 * #2 of the redesign). `deleted_at` powers Laravel's SoftDeletes trait so the
 * row is hidden from default queries but the data is never lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->softDeletes();
        });
        Schema::table('deliverables', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('deliverables', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};

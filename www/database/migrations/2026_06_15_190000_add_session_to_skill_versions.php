<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provenance for a proposed skill version: the work-session it arose from (e.g. a
 * "remember this" capture during a task). Lets the UI surface a proposal next to
 * the session — and, indirectly, in the review of that session's task — for
 * sign-off. Nullable: most versions have no session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_versions', function (Blueprint $table) {
            $table->foreignId('work_session_id')->nullable()->after('author_user_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('skill_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('work_session_id');
        });
    }
};

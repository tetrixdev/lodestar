<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review outcome handling. A section gets a `decision` (approved /
 * changes_requested) distinct from its sign-off, and the review gets an
 * `outcome` once the human finishes — which drives the task's next transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_sections', function (Blueprint $table) {
            $table->string('decision')->nullable()->after('status'); // approved|changes_requested
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('outcome')->nullable()->after('status'); // approved|changes_requested
        });
    }

    public function down(): void
    {
        Schema::table('review_sections', function (Blueprint $table) {
            $table->dropColumn('decision');
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('outcome');
        });
    }
};

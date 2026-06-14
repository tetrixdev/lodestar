<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A human must atomically self-assign a review before they can sign off its
 * sections. `assigned_to_user_id` holds the current reviewer (the human mirror
 * of an agent claiming a task). Nullable: a review starts unassigned, and the
 * claim is performed by a conditional UPDATE on this column. nullOnDelete so a
 * deleted user simply frees the review rather than dropping it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('assigned_to_user_id')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to_user_id');
        });
    }
};

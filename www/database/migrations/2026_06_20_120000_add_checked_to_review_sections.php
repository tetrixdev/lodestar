<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The human's manual-test progress, kept separate from the agent-authored
 * `checks` content. `checked` is the list of checklist item INDICES the human
 * has ticked off — persisted server-side (not localStorage) so it survives a
 * device switch and is visible to whoever opens the review. Re-authoring the
 * `checks` list (agent-side) never touches this human state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_sections', function (Blueprint $table) {
            $table->jsonb('checked')->nullable(); // [0, 2, ...] — indices into `checks` the human has ticked
        });
    }

    public function down(): void
    {
        Schema::table('review_sections', function (Blueprint $table) {
            $table->dropColumn('checked');
        });
    }
};

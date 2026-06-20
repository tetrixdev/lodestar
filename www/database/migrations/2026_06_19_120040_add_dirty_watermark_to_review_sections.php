<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dirty-section watermark for incremental re-review. When new commits touch
 * files a section covers (a task merging into the deliverable, or a re-review
 * after fixes), that section is flagged `stale`, its decision reset, and
 * `change_note` stamped with what changed / when — so the human only re-walks the
 * affected sections. `manual_steps` is the short-but-complete step-by-step a human
 * follows to test a functional section (incl. dry-run previews of invisible
 * outputs). `kind` types a section as input_screen / command / outbound_effect /
 * other, orthogonal to the protocol `mode`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_sections', function (Blueprint $table) {
            $table->boolean('stale')->default(false)->after('decision');
            $table->text('change_note')->nullable()->after('stale');
            $table->text('manual_steps')->nullable()->after('change_note');
            $table->string('kind')->nullable()->after('mode'); // input_screen|command|outbound_effect|other
        });
    }

    public function down(): void
    {
        Schema::table('review_sections', function (Blueprint $table) {
            $table->dropColumn(['stale', 'change_note', 'manual_steps', 'kind']);
        });
    }
};

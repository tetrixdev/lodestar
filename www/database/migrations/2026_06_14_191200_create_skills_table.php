<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A skill: the versioned prompt that drives one phase of the loop (plan /
 * develop / ai_review / merge). `kind=system` skills ship from code (read-only,
 * `user_id` null, upserted on deploy by key+version); `kind=user` skills are a
 * user's editable fork (`source_version` records the system version it was
 * cloned from). `get_skill` resolves which one an agent runs via skill_bindings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('kind');                 // system | user
            $table->string('key');                  // plan | develop | ai_review | merge
            $table->unsignedInteger('version')->default(1);
            $table->string('title');
            $table->text('body');                   // the prompt
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_version')->nullable(); // system version a fork came from
            $table->timestamps();

            // A system skill is unique per (key, version); user forks are not.
            $table->index(['kind', 'key', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};

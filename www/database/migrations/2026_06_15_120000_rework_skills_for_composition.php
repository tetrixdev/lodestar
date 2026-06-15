<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move skills from "fork the whole prompt" to LAYERED COMPOSITION.
 *
 * A `skills` row is now a *slot*: a (scope, owner, key) addressable layer with a
 * `mode` (append onto what's above it, or overwrite it). The prompt text lives
 * in `skill_versions` — every slot keeps a version history; exactly one version
 * is `active` and that's the one composition uses.
 *
 * Existing data is preserved: each old system skill becomes a system-scope
 * append slot, each user fork a personal-scope overwrite slot, each carrying one
 * active version cloned from its old body. The old `skill_bindings` table (the
 * pick-one-fork model) is dropped — composition replaces it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Versioned prompt bodies for every slot.
        Schema::create('skill_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->text('body');
            $table->string('status')->default('active'); // proposed|active|archived|rejected
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('proposed_by_ai')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['skill_id', 'status']);
        });

        // 2. Slot columns on skills (alongside the old ones for the backfill).
        Schema::table('skills', function (Blueprint $table) {
            $table->string('scope')->default('system')->after('id'); // system|team|personal|project
            $table->nullableMorphs('owner');                          // owner_type/owner_id; system → null
            $table->string('mode')->default('append')->after('key');  // append|overwrite
        });

        // 3. Backfill slots + one active version each from the old shape. On a
        //    fresh migrate (tests) `skills` is empty here, so this is a no-op.
        foreach (DB::table('skills')->get() as $old) {
            $isSystem = ($old->kind ?? 'system') === 'system';

            DB::table('skills')->where('id', $old->id)->update([
                'scope' => $isSystem ? 'system' : 'personal',
                'owner_type' => $isSystem ? null : User::class,
                'owner_id' => $isSystem ? null : $old->user_id,
                'mode' => $isSystem ? 'append' : 'overwrite',
            ]);

            DB::table('skill_versions')->insert([
                'skill_id' => $old->id,
                'version' => $old->version ?? 1,
                'title' => $old->title,
                'body' => $old->body,
                'status' => 'active',
                'author_user_id' => $isSystem ? null : $old->user_id,
                'proposed_by_ai' => false,
                'note' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 4. Drop the old per-row content + the bindings table.
        Schema::table('skills', function (Blueprint $table) {
            $table->dropIndex(['kind', 'key', 'version']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['kind', 'version', 'body', 'source_version']);
        });
        Schema::dropIfExists('skill_bindings');

        // 5. One slot per (scope, owner, key). System rows share a null owner;
        //    their single-per-key uniqueness is held by the idempotent seeder.
        Schema::table('skills', function (Blueprint $table) {
            $table->unique(['scope', 'owner_id', 'key']);
        });
    }

    public function down(): void
    {
        // One-way structural rework; recreate the old shape empty enough to roll
        // back the schema (not the data).
        Schema::table('skills', function (Blueprint $table) {
            $table->dropUnique(['scope', 'owner_id', 'key']);
            $table->string('kind')->default('system');
            $table->unsignedInteger('version')->default(1);
            $table->text('body')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_version')->nullable();
            $table->dropMorphs('owner');
            $table->dropColumn(['scope', 'mode']);
            $table->index(['kind', 'key', 'version']);
        });

        Schema::create('skill_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('phase');
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'project_id', 'phase']);
        });

        Schema::dropIfExists('skill_versions');
    }
};

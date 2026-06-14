<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A project — a group of repos with a shared goal (the home base that was the
 * `_stacks/<name>` folder). Owned by a user (multi-tenant by ownership); tasks,
 * work sessions and reviews all hang off it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->text('primary_goal')->nullable();
            $table->jsonb('repos')->nullable(); // [{ name, url }] until a Repo model earns its table
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};

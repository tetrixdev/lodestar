<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which repositories a project (= a "stack") spans. Many-to-many: a repo may sit
 * in several projects. A project may have zero repos while planning, but needs at
 * least one before a review/loop can run against it (enforced in app logic).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_repository', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'repository_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_repository');
    }
};

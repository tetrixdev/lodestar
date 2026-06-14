<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A GitHub repository ("owner/name"), read through a specific GitHub connection
 * (so Lodestar always knows which token can see it). Linked to projects via the
 * project_repository pivot — a repo may belong to several projects (stacks share
 * repos), and a project lists all its repos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('github_connection_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');             // owner/name
            $table->string('default_branch')->nullable();
            $table->timestamps();

            // The same repo read through one connection is a single row.
            $table->unique(['github_connection_id', 'full_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};

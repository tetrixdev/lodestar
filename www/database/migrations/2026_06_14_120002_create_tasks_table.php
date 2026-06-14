<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A kanban card (the tasks/<state>/<file>.md the board replaces). `status` is the
 * column it sits in; `cancelled` is the soft-delete (no hard delete). `position`
 * orders cards within a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('category')->nullable();   // grouping prefix, e.g. dm-engine / infra
            $table->text('body')->nullable();          // the task detail (markdown)
            $table->string('status')->default('open'); // open | doing | done | cancelled
            $table->integer('position')->default(0);   // order within the status column
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};

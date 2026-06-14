<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One linked GitHub account/token a user has connected. A user may have several
 * (e.g. "work" and "personal"); each Repository is read through the connection
 * it was linked with. The token is stored encrypted (see the model cast).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label');                 // "work", "personal"
            $table->string('github_login')->nullable(); // the account, fetched to confirm the token
            $table->text('token');                   // encrypted at the model layer
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_connections');
    }
};

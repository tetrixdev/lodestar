<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHEN a review was responded to. `created_at` is when the review was
 * requested; `concluded_at` is stamped the moment the human applies the outcome
 * (reviews.conclude), so a linked-reviews list can show both "requested" and
 * "responded" times. Null while the review is still open / awaiting a verdict.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->timestamp('concluded_at')->nullable()->after('outcome');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('concluded_at');
        });
    }
};

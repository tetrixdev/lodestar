<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let an agent report back whether each tool is actually installed/working in its
 * workspace, so the operator sees real status (not just intent). `last_status` is
 * ok|missing|error|unknown; `last_checked_at` stamps the last report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_tools', function (Blueprint $table) {
            $table->string('last_status')->nullable()->after('run'); // ok|missing|error|unknown
            $table->timestamp('last_checked_at')->nullable()->after('last_status');
        });
    }

    public function down(): void
    {
        Schema::table('project_tools', function (Blueprint $table) {
            $table->dropColumn(['last_status', 'last_checked_at']);
        });
    }
};

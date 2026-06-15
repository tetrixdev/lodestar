<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Self-heal the dev Vite server. On this box, editing a watched Blade/CSS file
 * can leave the Vite process "running" under supervisor while it has dropped
 * `public/hot` — Laravel then falls back to the (absent) build manifest and every
 * page 500s (ViteManifestNotFoundException). supervisor's autorestart never fires
 * because the process didn't actually exit. This command, scheduled every minute
 * in local, notices the missing hot file and restarts npm-dev so the dev server
 * recovers on its own within ~a minute (no human babysitting / manual restart).
 */
class EnsureViteRunning extends Command
{
    protected $signature = 'dev:ensure-vite';

    protected $description = 'Restart the Vite dev server if its hot file has vanished (local only).';

    public function handle(): int
    {
        if (! $this->laravel->environment('local')) {
            return self::SUCCESS;
        }

        if (file_exists(public_path('hot'))) {
            return self::SUCCESS;
        }

        $this->warn('public/hot missing — restarting npm-dev.');
        Process::run('supervisorctl restart npm-dev');

        return self::SUCCESS;
    }
}

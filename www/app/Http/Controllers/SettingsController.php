<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Embedding;
use App\Services\Embeddings\EmbeddingProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

/** A single landing page that gathers the account & integration settings. */
class SettingsController extends Controller
{
    public function index(EmbeddingProvider $provider): View
    {
        return view('settings.index', [
            'embeddings' => $this->embeddingStatus($provider),
        ]);
    }

    /** Run the key sanity check (GET /v1/models) and report it back on the panel. */
    public function testKey(EmbeddingProvider $provider): RedirectResponse
    {
        if (! $provider->isConfigured()) {
            return back()->with('embeddings_status', ['ok' => false, 'message' => 'No embedding key is configured.']);
        }

        $ok = $provider->validateKey();

        return back()->with('embeddings_status', [
            'ok' => $ok,
            'message' => $ok ? 'Key validated — OpenAI accepted it.' : 'Key did not validate (rejected or unreachable).',
        ]);
    }

    /** Kick off a full reconcile so the panel's counts catch up on demand. */
    public function resync(): RedirectResponse
    {
        Artisan::queue('lodestar:embed-sync');

        return back()->with('embeddings_status', ['ok' => true, 'message' => 'Re-sync queued — counts update as it runs.']);
    }

    /**
     * The AI & Embeddings panel data: is a key configured + valid, per-type
     * embedded/total counts, and the last-sync time. Read-only — there is no key
     * input field (the key is an operator env secret).
     *
     * @return array<string, mixed>
     */
    private function embeddingStatus(EmbeddingProvider $provider): array
    {
        $configured = $provider->isConfigured();

        // Per-type embedded vs total. Cheap counts; only run when configured.
        $types = config('lodestar.embeddings.types', []);
        $rows = [];
        $embeddedByType = $configured
            ? Embedding::query()->selectRaw('embeddable_type, count(*) as c')->groupBy('embeddable_type')->pluck('c', 'embeddable_type')
            : collect();

        foreach ($types as $label => $modelClass) {
            $morph = (new $modelClass)->getMorphClass();
            $rows[] = [
                'label' => str_replace('_', ' ', $label),
                'embedded' => (int) ($embeddedByType[$morph] ?? 0),
                'total' => $configured ? (int) $modelClass::query()->count() : 0,
            ];
        }

        return [
            'configured' => $configured,
            'model' => $provider->model(),
            'counts' => $rows,
            'last_sync' => $configured ? Embedding::query()->max('updated_at') : null,
        ];
    }
}

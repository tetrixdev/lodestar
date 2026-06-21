<?php

declare(strict_types=1);

return [
    // How long a task may sit in a working (*-ing) state with no progress before
    // the reaper (lodestar:reap-stalled-tasks) assumes its worker died and
    // re-queues it. Generous by default — legitimate work can take a while; this
    // only catches the genuinely stalled, not the merely slow.
    'task_lease_minutes' => (int) env('LODESTAR_TASK_LEASE_MINUTES', 60),

    // ── Embeddings / semantic search ──────────────────────────────────────────
    // Operator opt-in: the whole pipeline only runs when services.openai.key is
    // set. Absent or invalid, embedding is skipped (fail-safe) and search
    // degrades to empty. These knobs tune the OpenAI embeddings call + the
    // reconciler's batching; the API key itself lives in config/services.php.
    'embeddings' => [
        // The OpenAI embedding model and its vector dimensionality. The
        // `embeddings.embedding` column is vector(1536) — keep these in sync.
        'model' => env('LODESTAR_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('LODESTAR_EMBEDDING_DIMENSIONS', 1536),

        // How many objects the provider embeds per OpenAI request, and how many
        // the reconciler walks per chunk.
        'batch_size' => (int) env('LODESTAR_EMBEDDING_BATCH_SIZE', 100),

        // The queue the Embed/Forget jobs run on (a dedicated queue so embedding
        // backpressure never starves the lifecycle queue).
        'queue' => env('LODESTAR_EMBEDDING_QUEUE', 'embeddings'),
    ],
];

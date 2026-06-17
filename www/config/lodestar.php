<?php

declare(strict_types=1);

return [
    // How long a task may sit in a working (*-ing) state with no progress before
    // the reaper (lodestar:reap-stalled-tasks) assumes its worker died and
    // re-queues it. Generous by default — legitimate work can take a while; this
    // only catches the genuinely stalled, not the merely slow.
    'task_lease_minutes' => (int) env('LODESTAR_TASK_LEASE_MINUTES', 60),
];

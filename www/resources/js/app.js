import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import Sortable from 'sortablejs';
import mermaid from 'mermaid';

window.Alpine = Alpine;

// Register the collapse plugin so `x-collapse` animates show/hide transitions.
// Must run before Alpine.start() (called at the bottom of this file).
Alpine.plugin(collapse);

// Mermaid renders `<pre class="mermaid">` blocks the <x-markdown> component emits
// from ```mermaid fences (see components/markdown.blade.php). We drive rendering
// ourselves rather than startOnLoad because diagrams also arrive via x-html (the
// file modal) after page load.
mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' });

/**
 * Render every not-yet-processed mermaid block under `root` (defaults to the
 * whole document). Safe to call repeatedly — mermaid.run() with the
 * `.mermaid:not([data-processed])` selector skips already-rendered diagrams,
 * and a parse error is swallowed so one bad diagram can't blank the surface.
 */
window.renderMermaid = function (root) {
    const scope = root || document;
    const nodes = scope.querySelectorAll('pre.mermaid:not([data-processed="true"])');
    if (nodes.length === 0) return;
    // Surface render errors to the console — a swallowed error here is the usual
    // reason a diagram silently stays as text. One bad diagram still can't throw
    // out of here and blank the surface.
    mermaid.run({ nodes: [...nodes] }).catch((e) => console.error('[mermaid]', e));
};

// Render any mermaid present in server-rendered page markdown on first load.
document.addEventListener('DOMContentLoaded', () => window.renderMermaid());

/**
 * Kanban intra-status reordering. Lifecycle moves between statuses go through
 * the per-card transition controls (legal-only, server-validated). Drag is
 * therefore constrained to reordering WITHIN a phase column and never moves a
 * card across columns.
 *
 * On drop we PATCH /tasks/{id}/move with the dragged card's own status and the
 * ordered list of ids that share that status in the column, so the server can
 * rewrite positions to match exactly what the user sees. Cards of a different
 * status in the same column are ignored.
 */
window.initBoard = function (root, moveUrlTemplate, csrf) {
    const lists = root.querySelectorAll('[data-phase]');

    const persist = async (taskId, status, siblingIds) => {
        try {
            const res = await fetch(moveUrlTemplate.replace('__ID__', taskId), {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ status, order: siblingIds }),
            });
            if (!res.ok) {
                // Server rejected the move — reload to restore the true state.
                window.location.reload();
            }
        } catch (e) {
            window.location.reload();
        }
    };

    lists.forEach((list) => {
        Sortable.create(list, {
            // No shared group: dragging is confined to this column.
            animation: 150,
            ghostClass: 'opacity-40',
            draggable: '[data-task-id]',
            onEnd: (evt) => {
                const item = evt.item;
                const status = item.dataset.status;
                // The ordered ids of cards that share the dragged card's status.
                const siblingIds = Array.from(list.querySelectorAll('[data-task-id]'))
                    .filter((el) => el.dataset.status === status)
                    .map((el) => Number(el.dataset.taskId));
                persist(Number(item.dataset.taskId), status, siblingIds);
            },
        });
    });
};

Alpine.start();

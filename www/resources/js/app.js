import Alpine from 'alpinejs';
import Sortable from 'sortablejs';

window.Alpine = Alpine;

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

import Alpine from 'alpinejs';
import Sortable from 'sortablejs';

window.Alpine = Alpine;

/**
 * Kanban drag-and-drop. Each column is a Sortable list sharing the group
 * `board`, so cards can be reordered within a column and dragged across
 * columns. On drop we PATCH /tasks/{id}/move with the destination status and
 * the full ordered list of ids now in that column, so the server can rewrite
 * positions to match exactly what the user sees.
 */
window.initBoard = function (root, moveUrlTemplate, csrf) {
    const columns = root.querySelectorAll('[data-column]');

    const persist = async (taskId, status, list) => {
        const order = Array.from(list.querySelectorAll('[data-task-id]'))
            .map((el) => Number(el.dataset.taskId));

        try {
            const res = await fetch(moveUrlTemplate.replace('__ID__', taskId), {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ status, order }),
            });
            if (!res.ok) {
                // Server rejected the move — reload to restore the true state.
                window.location.reload();
            }
        } catch (e) {
            window.location.reload();
        }
    };

    columns.forEach((list) => {
        Sortable.create(list, {
            group: 'board',
            animation: 150,
            ghostClass: 'opacity-40',
            draggable: '[data-task-id]',
            onEnd: (evt) => {
                const destList = evt.to;
                const status = destList.dataset.column;
                const taskId = evt.item.dataset.taskId;
                persist(taskId, status, destList);
            },
        });
    });
};

Alpine.start();

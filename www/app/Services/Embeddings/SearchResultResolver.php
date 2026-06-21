<?php

declare(strict_types=1);

namespace App\Services\Embeddings;

use App\Models\PlaybookVersion;
use App\Models\Project;
use App\Models\Review;
use App\Models\ReviewFinding;
use App\Models\ReviewSection;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\WorkSession;
use Illuminate\Database\Eloquent\Model;

/**
 * Turns raw KNN hits ({embeddable_type, embeddable_id, distance}) into display
 * rows ({type, id, title, snippet, url}) by hydrating each object and asking it
 * for a human title + snippet + a link to open. Shared by the `search` MCP tool
 * and the in-app /search page so both render identically. Also maps the short
 * type labels a caller passes (e.g. `task`) to/from morph class names.
 */
class SearchResultResolver
{
    /** Short label => model class. Labels are what callers pass in `types[]`. */
    private const TYPES = [
        'project' => Project::class,
        'task' => Task::class,
        'work_session' => WorkSession::class,
        'review' => Review::class,
        'review_section' => ReviewSection::class,
        'review_finding' => ReviewFinding::class,
        'task_comment' => TaskComment::class,
        'playbook' => PlaybookVersion::class,
    ];

    /**
     * Map the short labels a caller passed to morph class names for the search
     * filter. Empty in → empty out (no type restriction).
     *
     * @param  list<string>  $labels
     * @return list<string>
     */
    public function morphTypesFor(array $labels): array
    {
        $out = [];
        foreach ($labels as $label) {
            if (isset(self::TYPES[$label])) {
                $out[] = (new (self::TYPES[$label]))->getMorphClass();
            }
        }

        return $out;
    }

    /**
     * Hydrate hits into display rows, preserving the KNN order and dropping any
     * whose object has since vanished.
     *
     * @param  list<array{embeddable_type: string, embeddable_id: int, distance: float}>  $hits
     * @return list<array{type: string, id: int, title: string, snippet: string, url: string|null, distance: float}>
     */
    public function resolve(array $hits): array
    {
        // Group ids by class so each model is loaded in one query.
        $byClass = [];
        foreach ($hits as $hit) {
            $class = $this->classFor($hit['embeddable_type']);
            if ($class === null) {
                continue;
            }
            $byClass[$class][] = $hit['embeddable_id'];
        }

        $loaded = [];
        foreach ($byClass as $class => $ids) {
            $loaded[$class] = $class::query()->whereKey($ids)->get()->keyBy(fn (Model $m) => $m->getKey());
        }

        $rows = [];
        foreach ($hits as $hit) {
            $class = $this->classFor($hit['embeddable_type']);
            $object = $class ? ($loaded[$class][$hit['embeddable_id']] ?? null) : null;
            if ($object === null) {
                continue;
            }

            $rows[] = [
                'type' => $this->labelFor($class),
                'id' => (int) $object->getKey(),
                'title' => $this->titleFor($object),
                'snippet' => $this->snippetFor($object),
                'url' => $this->urlFor($object),
                'distance' => $hit['distance'],
            ];
        }

        return $rows;
    }

    private function classFor(string $morph): ?string
    {
        // No morph map is enforced, so the stored type is the FQCN; accept the
        // short label too for forward-compatibility.
        if (in_array($morph, self::TYPES, true)) {
            return $morph;
        }

        return self::TYPES[$morph] ?? null;
    }

    private function labelFor(string $class): string
    {
        return (string) array_search($class, self::TYPES, true) ?: class_basename($class);
    }

    private function titleFor(Model $object): string
    {
        return match (true) {
            $object instanceof Project, $object instanceof Task, $object instanceof Review,
            $object instanceof WorkSession, $object instanceof ReviewSection,
            $object instanceof ReviewFinding => (string) $object->title,
            $object instanceof PlaybookVersion => trim(($object->playbook?->key ?? 'playbook').' — '.$object->title),
            $object instanceof TaskComment => 'Comment on task #'.$object->task_id,
            default => class_basename($object),
        };
    }

    private function snippetFor(Model $object): string
    {
        /** @var string $text */
        $text = method_exists($object, 'embeddingText') ? $object->embeddingText() : '';
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 197).'…' : $text;
    }

    private function urlFor(Model $object): ?string
    {
        return match (true) {
            $object instanceof Project => route('projects.show', $object),
            $object instanceof Task => route('tasks.show', $object),
            $object instanceof TaskComment => $object->task_id ? route('tasks.show', $object->task_id) : null,
            $object instanceof Review, $object instanceof ReviewSection, $object instanceof ReviewFinding => $this->reviewUrl($object),
            default => null,
        };
    }

    private function reviewUrl(Model $object): ?string
    {
        $reviewId = match (true) {
            $object instanceof Review => $object->id,
            $object instanceof ReviewSection => $object->review_id,
            $object instanceof ReviewFinding => $object->section?->review_id,
            default => null,
        };

        return $reviewId ? route('reviews.show', $reviewId) : null;
    }
}

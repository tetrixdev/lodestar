@props(['file'])

@php
    /**
     * One clickable trigger that opens the changed-file viewer modal. The single
     * place the `open-file` Alpine event is dispatched — used by both the
     * changed-files tree and the per-section file references, so the payload
     * shape ({id, path, oldPath, status, additions, deletions, markdown}) lives
     * in one spot. `$file` is a ReviewFile (fully loaded). `oldPath` is the
     * pre-rename path for renamed files (null otherwise).
     *
     * @var \App\Models\ReviewFile $file
     */
    $isMarkdown = in_array(strtolower(pathinfo($file->path, PATHINFO_EXTENSION)), ['md', 'markdown'], true);
    $payload = [
        'id' => (int) $file->id,
        'path' => $file->path,
        'oldPath' => $file->old_path,
        'status' => $file->status,
        'additions' => (int) $file->additions,
        'deletions' => (int) $file->deletions,
        'markdown' => $isMarkdown,
    ];
    $dispatch = "\$dispatch('open-file', ".\Illuminate\Support\Js::from($payload).')';
@endphp

<div {{ $attributes->merge(['role' => 'button', 'tabindex' => '0', 'class' => 'cursor-pointer']) }}
     @click="{{ $dispatch }}"
     @keydown.enter="{{ $dispatch }}">
    {{ $slot }}
</div>

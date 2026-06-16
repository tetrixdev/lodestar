@props(['file'])

@php
    /**
     * One clickable trigger that opens the changed-file viewer modal. The single
     * place the `open-file` Alpine event is dispatched — used by both the
     * changed-files tree and the per-section file references, so the payload
     * shape ({id, path, status, additions, deletions, markdown}) lives in one
     * spot. `$file` is a ReviewFile (fully loaded).
     *
     * @var \App\Models\ReviewFile $file
     */
    $isMarkdown = in_array(strtolower(pathinfo($file->path, PATHINFO_EXTENSION)), ['md', 'markdown'], true);
    $payload = [
        'id' => (int) $file->id,
        'path' => $file->path,
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

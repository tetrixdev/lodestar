{{--
    Per-section attachments (task #100 C). Lives inside a `section()` Alpine scope
    (reviews/show.blade.php) which owns the attachments array + upload/paste/drop/
    delete handlers. Uploads fire IMMEDIATELY (so paste-while-open works and a file
    can be deleted before the section is sent); thumbnails render inline; each has a
    delete button. Only the review's holder can attach/delete — others see the list
    read-only. The textarea above already supports type-while-paste; pasting an
    image there is intercepted and uploaded here.

    Expects in scope: $isAssignee (bool), $s (ReviewSection with ->attachments).
--}}
<div class="mt-3"
     @if ($isAssignee)
        @paste="onPaste($event)"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop($event)"
     @endif
     :class="dragging ? 'ring-2 ring-indigo-300 rounded-md' : ''">
    <div class="flex items-center justify-between">
        <p class="text-[11px] font-medium text-gray-400 uppercase tracking-wide">Attachments</p>
        @if ($isAssignee)
            <label class="cursor-pointer text-[11px] font-medium text-indigo-600 hover:text-indigo-800">
                <input type="file" class="hidden" @change="onPick($event)"
                       accept="image/*,.pdf,.txt,.md,.log,.csv,.json,.zip">
                + Add file / paste an image
            </label>
        @endif
    </div>

    <ul class="mt-2 flex flex-wrap gap-2">
        <template x-for="a in attachments" :key="a.id">
            <li class="relative group/att rounded-md border border-gray-200 bg-gray-50 overflow-hidden"
                :class="a.is_image ? 'w-28 h-28' : 'px-3 py-2 max-w-[14rem]'">
                <template x-if="a.is_image">
                    <a :href="a.url" target="_blank" rel="noopener">
                        <img :src="a.url" :alt="a.name" class="w-28 h-28 object-cover">
                    </a>
                </template>
                <template x-if="!a.is_image">
                    <a :href="a.url" target="_blank" rel="noopener"
                       class="flex items-center gap-2 text-xs text-gray-700 hover:text-indigo-600">
                        <svg class="size-4 shrink-0 text-gray-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 0 1 2-2h4.586A2 2 0 0 1 12 2.586L15.414 6A2 2 0 0 1 16 7.414V16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4Zm2 6a1 1 0 0 1 1-1h6a1 1 0 1 1 0 2H7a1 1 0 0 1-1-1Zm1 3a1 1 0 1 0 0 2h6a1 1 0 1 0 0-2H7Z" clip-rule="evenodd"/></svg>
                        <span class="truncate" x-text="a.name"></span>
                    </a>
                </template>
                @if ($isAssignee)
                    <button type="button" @click="removeAttachment(a)"
                            class="absolute top-0.5 right-0.5 rounded-full bg-white/90 text-rose-600 hover:bg-rose-50 size-5 grid place-items-center text-xs shadow"
                            title="Delete attachment">&times;</button>
                @endif
            </li>
        </template>
        <template x-if="attachments.length === 0">
            <li class="text-xs text-gray-400 italic">None yet.</li>
        </template>
    </ul>

    <p x-show="uploading" x-cloak class="mt-1 text-[11px] text-gray-400">Uploading…</p>
    <p x-show="uploadError" x-cloak class="mt-1 text-[11px] text-rose-500" x-text="uploadError"></p>
</div>

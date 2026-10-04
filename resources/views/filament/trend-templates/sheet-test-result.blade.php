@php
    /** @var string $sheetUrl */
    /** @var string $photoUrl */
    /** @var string $endpointId */
@endphp

<div class="space-y-4 text-sm">
    <p class="text-gray-600 dark:text-gray-300">
        Sheet generated with <code class="text-xs">{{ $endpointId }}</code>. Check identity / outfit / multi-angle layout before publishing.
    </p>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <div class="mb-1.5 text-xs font-medium uppercase tracking-wide text-gray-500">Source face</div>
            <a href="{{ $photoUrl }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                <img src="{{ $photoUrl }}" alt="Source face" class="max-h-64 w-full object-contain bg-black/5 dark:bg-black/20" />
            </a>
        </div>
        <div>
            <div class="mb-1.5 text-xs font-medium uppercase tracking-wide text-gray-500">Character sheet</div>
            <a href="{{ $sheetUrl }}" target="_blank" rel="noopener" class="block overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                <img src="{{ $sheetUrl }}" alt="Character sheet" class="max-h-96 w-full object-contain bg-black/5 dark:bg-black/20" />
            </a>
        </div>
    </div>

    <div class="flex flex-wrap gap-3 text-xs">
        <a href="{{ $sheetUrl }}" target="_blank" rel="noopener" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
            Open sheet URL
        </a>
        <a href="{{ $photoUrl }}" target="_blank" rel="noopener" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
            Open source photo
        </a>
    </div>
</div>

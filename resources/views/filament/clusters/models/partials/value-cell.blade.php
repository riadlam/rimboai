@php
    $kind = $kind ?? 'text';
    $tone = $tone ?? null;
    $chips = $kind === 'chips' ? $this->normalizeList($value) : [];
    $chipClass = match ($tone) {
        'fal' => 'inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-800 ring-1 ring-inset ring-sky-200 dark:bg-sky-950/50 dark:text-sky-200 dark:ring-sky-800',
        'ours' => 'inline-flex items-center rounded-md bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-800 ring-1 ring-inset ring-violet-200 dark:bg-violet-950/50 dark:text-violet-200 dark:ring-violet-800',
        default => 'inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-700 ring-1 ring-inset ring-gray-200 dark:bg-white/10 dark:text-gray-200 dark:ring-white/10',
    };
@endphp

@if ($kind === 'chips')
    @if ($chips === [])
        <span class="text-xs text-gray-400">—</span>
    @else
        <div class="flex max-w-2xl flex-wrap gap-1">
            @foreach ($chips as $chip)
                <span class="{{ $chipClass }}">{{ $chip }}</span>
            @endforeach
        </div>
    @endif
@elseif ($kind === 'badge' || $kind === 'bool')
    @if ($value === null || $value === '')
        <span class="text-xs text-gray-400">—</span>
    @else
        <span class="{{ $chipClass }}">{{ $this->formatScalar($value, $kind) }}</span>
    @endif
@elseif ($kind === 'price')
    <span class="font-mono text-sm tabular-nums text-gray-900 dark:text-gray-100">{{ $this->formatScalar($value, 'price') }}</span>
@else
    <span class="break-all text-sm text-gray-800 dark:text-gray-200">{{ $this->formatScalar($value, $kind) }}</span>
@endif

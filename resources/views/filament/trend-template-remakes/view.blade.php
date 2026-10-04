@php
    /** @var \App\Models\UserVideoCreation $record */
    $settings = is_array($record->settings) ? $record->settings : [];
    $sheets = is_array($settings['character_sheets'] ?? null) ? $settings['character_sheets'] : [];
    $faces = [];
    foreach (is_array($record->input_assets) ? $record->input_assets : [] as $asset) {
        if (! is_array($asset)) {
            continue;
        }
        $role = (string) ($asset['role'] ?? '');
        $url = $asset['fal_url'] ?? $asset['url'] ?? null;
        if (! is_string($url) || $url === '') {
            continue;
        }
        if (str_starts_with($role, 'face_') || $role === 'reference') {
            $faces[] = [
                'url' => $url,
                'label' => (string) ($asset['slot_key'] ?? $role),
            ];
        }
    }
    $videoUrl = $record->result_video_url ?: $record->result_preview_url;
    $thumbnail = $record->thumbnail_url;
    $templateSlug = (string) ($settings['trend_template_slug'] ?? '');
    $templateId = $settings['from_trend_template_id'] ?? null;
@endphp

<div class="space-y-6 text-sm">
    <div class="grid gap-3 sm:grid-cols-2">
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">User</div>
            <div class="mt-1 font-medium text-gray-950 dark:text-white">
                {{ $record->user?->name ?: '—' }}
                @if ($record->user?->email)
                    <span class="font-normal text-gray-500">({{ $record->user->email }})</span>
                @endif
            </div>
        </div>
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Template</div>
            <div class="mt-1 font-medium text-gray-950 dark:text-white">
                {{ $templateSlug !== '' ? $templateSlug : ('#'.$templateId) }}
            </div>
        </div>
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</div>
            <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $record->status }}</div>
        </div>
        <div>
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Tokens</div>
            <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $record->credits_charged ?? ($settings['credits'] ?? '—') }}</div>
        </div>
    </div>

    @if ($record->progress_message || $record->error_message)
        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
            @if ($record->progress_message)
                <div><span class="text-gray-500">Progress:</span> {{ $record->progress_message }}</div>
            @endif
            @if ($record->error_message)
                <div class="mt-1 text-danger-600 dark:text-danger-400">{{ $record->error_message }}</div>
            @endif
        </div>
    @endif

    <div>
        <div class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            Uploaded face photos ({{ count($faces) }})
        </div>
        @if ($faces === [])
            <p class="text-gray-500">No face photos stored.</p>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach ($faces as $face)
                    <a href="{{ $face['url'] }}" target="_blank" rel="noopener" class="group block overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                        <img src="{{ $face['url'] }}" alt="{{ $face['label'] }}" class="aspect-[3/4] w-full object-cover transition group-hover:opacity-90" />
                        <div class="truncate px-2 py-1.5 text-xs text-gray-600 dark:text-gray-300">{{ $face['label'] }}</div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <div>
        <div class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            Character sheets ({{ count($sheets) }})
        </div>
        @if ($sheets === [])
            <p class="text-gray-500">No character sheets yet (still generating or failed before sheets).</p>
        @else
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ($sheets as $sheet)
                    @php
                        $sheetUrl = is_array($sheet) ? ($sheet['sheet_url'] ?? null) : null;
                        $photoUrl = is_array($sheet) ? ($sheet['photo_url'] ?? null) : null;
                        $label = is_array($sheet)
                            ? trim(($sheet['image_tag'] ?? '').' '.($sheet['slot_key'] ?? '').' '.($sheet['role'] ?? ''))
                            : '';
                    @endphp
                    @if (is_string($sheetUrl) && $sheetUrl !== '')
                        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                            <a href="{{ $sheetUrl }}" target="_blank" rel="noopener">
                                <img src="{{ $sheetUrl }}" alt="Character sheet" class="w-full object-contain bg-black/5 dark:bg-black/20" />
                            </a>
                            <div class="space-y-1 px-3 py-2 text-xs text-gray-600 dark:text-gray-300">
                                <div class="font-medium text-gray-900 dark:text-white">{{ $label ?: 'Sheet' }}</div>
                                @if (is_string($photoUrl) && $photoUrl !== '')
                                    <a href="{{ $photoUrl }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">
                                        Source face photo
                                    </a>
                                @endif
                                <a href="{{ $sheetUrl }}" target="_blank" rel="noopener" class="block text-primary-600 hover:underline dark:text-primary-400">
                                    Open sheet
                                </a>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>

    <div>
        <div class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            Generated video
        </div>
        @if (is_string($videoUrl) && $videoUrl !== '')
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-black dark:border-white/10">
                <video
                    src="{{ $videoUrl }}"
                    @if (is_string($thumbnail) && $thumbnail !== '') poster="{{ $thumbnail }}" @endif
                    controls
                    playsinline
                    class="max-h-[420px] w-full"
                ></video>
            </div>
            <a href="{{ $videoUrl }}" target="_blank" rel="noopener" class="mt-2 inline-block text-xs text-primary-600 hover:underline dark:text-primary-400">
                Open video URL
            </a>
        @else
            <p class="text-gray-500">No result video yet.</p>
        @endif
    </div>
</div>

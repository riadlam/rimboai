<x-filament-panels::page>
    @php
        $fal = $fal ?? null;
        $dbRow = $dbRow ?? null;
        $error = $error ?? null;
        $issues = $issues ?? [];
        $rows = $rows ?? [];
        $uiOptions = $uiOptions ?? null;
        $showRaw = $showRaw ?? false;
        $rawFal = $rawFal ?? null;
        $extracted = is_array($fal['extracted'] ?? null) ? $fal['extracted'] : [];
        $pricing = is_array($fal['pricing'] ?? null) ? $fal['pricing'] : [];
        $high = collect($issues)->where('severity', 'high')->count();
        $medium = collect($issues)->where('severity', 'medium')->count();
        $info = collect($issues)->where('severity', 'info')->count();
        $matched = collect($rows)->where('match', true)->count();
        $grouped = $this->groupedRows();
        $falUnit = $extracted['unit'] ?? ($pricing['unit'] ?? null);
        $falPrice = $extracted['unit_price'] ?? ($pricing['unit_price'] ?? null);
        $falStatus = $extracted['status'] ?? ($fal['model']['status'] ?? null);
    @endphp

    @if ($error && ! $fal)
        <div class="rounded-xl border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-200">
            {{ $error }}
        </div>
    @else
        @if ($error)
            <div class="mb-4 rounded-xl border border-warning-300 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">
                {{ $error }}
            </div>
        @endif

        {{-- Overview stats --}}
        <div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Availability</div>
                <div class="mt-1.5 text-xl font-semibold capitalize tracking-tight text-gray-950 dark:text-white">{{ $this->formatScalar($falStatus) }}</div>
                <div class="mt-1 text-xs text-gray-500">DB: {{ $this->formatScalar($dbRow['status'] ?? null) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Fal price</div>
                <div class="mt-1.5 text-xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $this->formatScalar($falPrice, 'price') }}</div>
                <div class="mt-1 text-xs text-gray-500">
                    per {{ $this->formatScalar($falUnit) }}
                    @if (! empty($extracted['unit_raw']) && ($extracted['unit_raw'] ?? null) !== $falUnit)
                        · raw {{ $extracted['unit_raw'] }}
                    @endif
                </div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">DB price</div>
                <div class="mt-1.5 text-xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $this->formatScalar($dbRow['unit_price'] ?? null, 'price') }}</div>
                <div class="mt-1 text-xs text-gray-500">per {{ $this->formatScalar($dbRow['unit'] ?? null) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Health</div>
                <div class="mt-1.5 text-xl font-semibold tracking-tight">
                    @if ($high === 0 && $medium === 0)
                        <span class="text-success-600 dark:text-success-400">Synced</span>
                    @else
                        <span class="text-danger-600 dark:text-danger-400">{{ $high + $medium }} issues</span>
                    @endif
                </div>
                <div class="mt-1 text-xs text-gray-500">{{ $high }} high · {{ $medium }} medium · {{ $info }} info · {{ $matched }} OK</div>
            </div>
        </div>

        {{-- Mismatches as admin table --}}
        @if (count($issues) > 0)
            <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50/80 px-4 py-3 dark:border-white/10 dark:bg-white/[0.03]">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Action required</h3>
                        <p class="text-xs text-gray-500">Mismatches between Fal and Rimbo — Fix writes Fal → DB</p>
                    </div>
                    <span class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200">{{ count($issues) }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:border-white/5">
                                <th class="w-28 px-4 py-2.5">Severity</th>
                                <th class="w-40 px-4 py-2.5">Field</th>
                                <th class="px-4 py-2.5">Fal</th>
                                <th class="px-4 py-2.5">DB / UI</th>
                                <th class="min-w-[12rem] px-4 py-2.5">Note</th>
                                <th class="w-24 px-4 py-2.5 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($issues as $issue)
                                @php
                                    $sev = $issue['severity'] ?? 'info';
                                    $sevClass = match ($sev) {
                                        'high' => 'bg-danger-50 text-danger-700 dark:bg-danger-950 dark:text-danger-200',
                                        'medium' => 'bg-warning-50 text-warning-800 dark:bg-warning-950 dark:text-warning-200',
                                        default => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300',
                                    };
                                    $issueField = (string) ($issue['field'] ?? '');
                                @endphp
                                <tr class="align-top">
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide {{ $sevClass }}">{{ $sev }}</span>
                                    </td>
                                    <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $issueField }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            @forelse ($this->normalizeList($issue['fal'] ?? null) as $chip)
                                                <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-800 ring-1 ring-inset ring-sky-200 dark:bg-sky-950/50 dark:text-sky-200 dark:ring-sky-800">{{ $chip }}</span>
                                            @empty
                                                @if (is_scalar($issue['fal'] ?? null) || ($issue['fal'] ?? null) === null)
                                                    <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-800 ring-1 ring-inset ring-sky-200 dark:bg-sky-950/50 dark:text-sky-200 dark:ring-sky-800">{{ $this->formatScalar($issue['fal'] ?? null) }}</span>
                                                @endif
                                            @endforelse
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-1">
                                            @forelse ($this->normalizeList($issue['db'] ?? null) as $chip)
                                                <span class="inline-flex items-center rounded-md bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-800 ring-1 ring-inset ring-violet-200 dark:bg-violet-950/50 dark:text-violet-200 dark:ring-violet-800">{{ $chip }}</span>
                                            @empty
                                                @if (is_scalar($issue['db'] ?? null) || ($issue['db'] ?? null) === null)
                                                    <span class="inline-flex items-center rounded-md bg-violet-50 px-2 py-0.5 text-[11px] font-medium text-violet-800 ring-1 ring-inset ring-violet-200 dark:bg-violet-950/50 dark:text-violet-200 dark:ring-violet-800">{{ $this->formatScalar($issue['db'] ?? null) }}</span>
                                                @endif
                                            @endforelse
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-xs leading-relaxed text-gray-600 dark:text-gray-300">{{ $issue['note'] ?? '' }}</td>
                                    <td class="px-4 py-3 text-end">
                                        @if ($issue['fixable'] ?? false)
                                            <button
                                                type="button"
                                                wire:click="fixMismatch(@js($issueField))"
                                                wire:confirm="Apply Fal value(s) for {{ $issueField }} to the DB?"
                                                class="inline-flex items-center rounded-lg bg-primary-600 px-2.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-primary-500"
                                            >
                                                Fix
                                            </button>
                                        @else
                                            <span class="text-xs text-gray-400">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <div class="mb-6 flex items-center gap-3 rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-sm text-success-800 dark:border-success-800 dark:bg-success-950 dark:text-success-200">
                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-success-600 text-[11px] font-bold text-white">✓</span>
                No mismatches — Fal and Rimbo look aligned for this model.
            </div>
        @endif

        {{-- Grouped field comparison --}}
        <div class="space-y-4">
            @forelse ($grouped as $group => $groupRows)
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex items-center justify-between gap-3 border-b border-gray-200 bg-gray-50/80 px-4 py-3 dark:border-white/10 dark:bg-white/[0.03]">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $group }}</h3>
                            <p class="text-xs text-gray-500">
                                {{ collect($groupRows)->where('match', false)->count() }} diff ·
                                {{ collect($groupRows)->where('match', true)->count() }} match
                            </p>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-100 text-left text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:border-white/5">
                                    <th class="w-44 px-4 py-2.5">Field</th>
                                    <th class="px-4 py-2.5">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span> Fal
                                        </span>
                                    </th>
                                    <th class="px-4 py-2.5">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="h-1.5 w-1.5 rounded-full bg-violet-500"></span> DB / Lab UI
                                        </span>
                                    </th>
                                    <th class="w-20 px-4 py-2.5 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($groupRows as $row)
                                    <tr @class([
                                        'align-top',
                                        'bg-danger-50/40 dark:bg-danger-950/20' => $row['match'] === false,
                                    ])>
                                        <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $row['label'] }}</td>
                                        <td class="px-4 py-3">
                                            @include('filament.clusters.models.partials.value-cell', [
                                                'value' => $row['fal'],
                                                'kind' => $row['kind'],
                                                'tone' => 'fal',
                                            ])
                                        </td>
                                        <td class="px-4 py-3">
                                            @include('filament.clusters.models.partials.value-cell', [
                                                'value' => $row['ours'],
                                                'kind' => $row['kind'],
                                                'tone' => 'ours',
                                            ])
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            @if ($row['match'] === true)
                                                <span class="inline-flex rounded-md bg-success-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-success-700 dark:bg-success-950 dark:text-success-200">OK</span>
                                            @elseif ($row['match'] === false)
                                                <span class="inline-flex rounded-md bg-danger-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-danger-700 dark:bg-danger-950 dark:text-danger-200">Diff</span>
                                            @else
                                                <span class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-gray-500 dark:bg-white/10 dark:text-gray-400">N/A</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-gray-200 bg-white px-4 py-8 text-center text-sm text-gray-500 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    No comparable fields available.
                </div>
            @endforelse
        </div>

        @if (! empty($uiOptions['note']))
            <p class="mt-4 text-xs text-gray-500">{{ $uiOptions['note'] }}</p>
        @endif

        @if ($showRaw)
            <div class="mt-6 grid gap-4 xl:grid-cols-2">
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="border-b border-gray-200 bg-gray-50/80 px-4 py-3 dark:border-white/10 dark:bg-white/[0.03]">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Raw Fal</h3>
                    </div>
                    <pre class="max-h-[50vh] overflow-auto p-4 font-mono text-[11px] leading-relaxed text-gray-700 dark:text-gray-300">{{ $this->pretty($rawFal) }}</pre>
                </div>
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="border-b border-gray-200 bg-gray-50/80 px-4 py-3 dark:border-white/10 dark:bg-white/[0.03]">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Raw DB</h3>
                    </div>
                    <pre class="max-h-[50vh] overflow-auto p-4 font-mono text-[11px] leading-relaxed text-gray-700 dark:text-gray-300">{{ $this->pretty($dbRow) }}</pre>
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>

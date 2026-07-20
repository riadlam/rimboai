<?php

namespace App\Filament\Clusters\Models\Pages;

use App\Models\TextToImageModel;
use App\Models\TextToMusicModel;
use App\Models\TextToVideoModel;
use App\Models\TextToVoiceModel;
use App\Services\FalModelInspector;
use App\Services\FalModelMismatchFixer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

class CompareModel extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'models/compare/{catalog}/{record}';

    protected static ?string $title = 'Compare model';

    protected string $view = 'filament.clusters.models.pages.compare-model';

    public string $catalog = '';

    public int|string $record = '';

    /** @var array<string, mixed>|null */
    public ?array $dbRow = null;

    /** @var array<string, mixed>|null */
    public ?array $fal = null;

    /** Heavy OpenAPI / raw Fal payload — only hydrated when Show raw is on. */
    public ?array $rawFal = null;

    /** @var list<array<string, mixed>> */
    public array $issues = [];

    /** @var array{resolutions: list<string>, aspect_ratios: list<string>, note: string}|null */
    public ?array $uiOptions = null;

    /** @var list<array{label: string, fal: mixed, ours: mixed, match: ?bool, kind: string}> */
    public array $rows = [];

    public ?string $error = null;

    public bool $showRaw = false;

    public function mount(string $catalog, int|string $record): void
    {
        $this->catalog = $catalog;
        $this->record = $record;

        $model = $this->resolveRecord($catalog, $record);
        if (! $model) {
            $this->error = 'Model not found in DB.';

            return;
        }

        $this->dbRow = $model->toArray();
        $this->uiOptions = app(FalModelInspector::class)->labUiOptions($catalog);
        $this->loadFal(fresh: false);
    }

    public function refreshFal(): void
    {
        $this->loadFal(fresh: true);
        Notification::make()
            ->title('Fal data refreshed')
            ->success()
            ->send();
    }

    public function fixAllMismatches(): void
    {
        $this->runFix(null);
    }

    public function fixMismatch(string $issueField): void
    {
        $fixer = app(FalModelMismatchFixer::class);
        $key = $fixer->mapIssueField($issueField);
        if ($key === null) {
            Notification::make()
                ->title('This mismatch cannot be auto-fixed')
                ->warning()
                ->send();

            return;
        }

        $this->runFix([$key]);
    }

    /**
     * @param  list<string>|null  $fields
     */
    protected function runFix(?array $fields): void
    {
        $model = $this->resolveRecord($this->catalog, $this->record);
        if (! $model) {
            Notification::make()->title('Model not found')->danger()->send();

            return;
        }

        $result = app(FalModelMismatchFixer::class)->fix($model, $this->catalog, $fields, fresh: true);

        if (! ($result['ok'] ?? false)) {
            Notification::make()
                ->title('Fix failed')
                ->body((string) ($result['error'] ?? 'Unknown error'))
                ->danger()
                ->send();

            return;
        }

        $applied = $result['applied'] ?? [];
        $model->refresh();
        $this->dbRow = $model->toArray();
        $this->loadFal(fresh: false);

        // Warm list-page mismatch badge cache for this model.
        app(FalModelInspector::class)->mismatchSummary(
            (string) ($this->dbRow['endpoint_id'] ?? ''),
            $this->dbRow,
            $this->catalog,
            fresh: true,
            fetchIfMissing: true,
        );

        if ($applied === []) {
            Notification::make()
                ->title('Nothing to apply')
                ->body('No writable Fal values for the selected fields.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Mismatch fixed')
            ->body('Updated: '.implode(', ', $applied))
            ->success()
            ->send();
    }

    public function toggleRaw(): void
    {
        $this->showRaw = ! $this->showRaw;
        // Drop heavy payload from Livewire state when hidden.
        if (! $this->showRaw) {
            $this->rawFal = null;
        } elseif ($this->rawFal === null && is_array($this->dbRow)) {
            $endpointId = (string) ($this->dbRow['endpoint_id'] ?? '');
            if ($endpointId !== '') {
                $inspect = app(FalModelInspector::class)->inspect($endpointId, false);
                $this->rawFal = [
                    'openapi' => $inspect['openapi'] ?? null,
                    'raw' => $inspect['raw'] ?? null,
                ];
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'error' => $this->error,
            'fal' => $this->fal,
            'dbRow' => $this->dbRow,
            'issues' => $this->issues,
            'rows' => $this->rows,
            'uiOptions' => $this->uiOptions,
            'showRaw' => $this->showRaw,
            'rawFal' => $this->rawFal,
        ];
    }

    protected function loadFal(bool $fresh): void
    {
        $endpointId = (string) ($this->dbRow['endpoint_id'] ?? '');
        if ($endpointId === '') {
            $this->error = 'DB row has no endpoint_id.';
            $this->fal = null;
            $this->issues = [];
            $this->rows = [];

            return;
        }

        /** @var FalModelInspector $inspector */
        $inspector = app(FalModelInspector::class);
        $inspect = $inspector->inspect($endpointId, $fresh);

        // Keep Livewire state small — OpenAPI/raw payloads blow up the page snapshot.
        $this->rawFal = [
            'openapi' => $inspect['openapi'] ?? null,
            'raw' => $inspect['raw'] ?? null,
        ];
        if (! $this->showRaw) {
            // Don't keep huge OpenAPI in the Livewire snapshot until user asks.
            $this->rawFal = null;
        }
        $this->fal = [
            'ok' => (bool) ($inspect['ok'] ?? false),
            'error' => $inspect['error'] ?? null,
            'endpoint_id' => $inspect['endpoint_id'] ?? $endpointId,
            'model' => $inspect['model'] ?? null,
            'pricing' => $inspect['pricing'] ?? null,
            'extracted' => is_array($inspect['extracted'] ?? null) ? $inspect['extracted'] : [],
        ];
        $this->error = ($this->fal['ok'] ?? false) ? null : ($this->fal['error'] ?? 'Fal inspect failed');
        $this->uiOptions = $inspector->labUiOptions($this->catalog);
        $this->issues = $inspector->diff(
            is_array($this->fal['extracted'] ?? null) ? $this->fal['extracted'] : [],
            is_array($this->dbRow) ? $this->dbRow : [],
            $this->catalog,
        );
        $this->rows = $this->buildComparisonRows();

        // Keep the lab list badge in sync after opening/refreshing compare.
        $inspector->mismatchSummary(
            $endpointId,
            is_array($this->dbRow) ? $this->dbRow : [],
            $this->catalog,
            fresh: false,
            fetchIfMissing: true,
        );
    }

    /**
     * @return list<array{label: string, fal: mixed, ours: mixed, match: ?bool, kind: string}>
     */
    protected function buildComparisonRows(): array
    {
        $extracted = is_array($this->fal['extracted'] ?? null) ? $this->fal['extracted'] : [];
        $model = is_array($this->fal['model'] ?? null) ? $this->fal['model'] : [];
        $db = is_array($this->dbRow) ? $this->dbRow : [];
        $ui = is_array($this->uiOptions) ? $this->uiOptions : [];

        $dbAspects = $db['aspect_ratios'] ?? [];
        $dbResolutions = $db['resolutions'] ?? [];
        $oursAspects = ! empty($dbAspects) ? $dbAspects : ($ui['aspect_ratios'] ?? []);
        $oursResolutions = ! empty($dbResolutions) ? $dbResolutions : ($ui['resolutions'] ?? []);

        $rows = [
            $this->row('Identity', 'Endpoint', $model['endpoint_id'] ?? ($this->fal['endpoint_id'] ?? null), $db['endpoint_id'] ?? null, 'text'),
            $this->row('Identity', 'Name', $model['title'] ?? null, $db['name'] ?? null, 'text', match: null),
            $this->row('Identity', 'Status', $extracted['status'] ?? ($model['status'] ?? null), $db['status'] ?? null, 'badge'),
            $this->row('Identity', 'Category', $extracted['category'] ?? ($model['category'] ?? null), null, 'text', match: null),
            $this->row('Pricing', 'Billing unit', $extracted['unit'] ?? null, $db['unit'] ?? null, 'badge'),
            $this->row('Pricing', 'Fal raw unit', $extracted['unit_raw'] ?? null, null, 'badge', match: null),
            $this->row('Pricing', 'Unit price (USD)', $extracted['unit_price'] ?? null, $db['unit_price'] ?? null, 'price'),
            $this->row('Duration', 'Max duration', $extracted['max_duration'] ?? null, $db['max_duration'] ?? null, 'text'),
            $this->row('Duration', 'Allowed durations', $extracted['durations'] ?? [], $db['enums'] ?? [], 'chips'),
            $this->row('Output options', 'Resolutions', $extracted['resolutions'] ?? [], $oursResolutions, 'chips'),
            $this->row('Output options', 'Aspect ratios', $extracted['aspect_ratios'] ?? [], $oursAspects, 'chips'),
            $this->row('Output options', 'Quantities', $extracted['quantities'] ?? [], null, 'chips', match: null),
            $this->row('Capabilities', 'Audio', $extracted['supports_audio_hint'] ?? null, $db['supports_audio'] ?? null, 'bool'),
            $this->row('Capabilities', 'Image input', $extracted['has_image_url_input'] ?? null, null, 'bool', match: null),
            $this->row('Capabilities', 'First frame', null, $db['supports_first_frame'] ?? null, 'bool', match: null),
            $this->row('Capabilities', 'Last frame', null, $db['supports_last_frame'] ?? null, 'bool', match: null),
            $this->row('Meta', 'OpenAPI inputs', $extracted['input_properties'] ?? [], null, 'chips', match: null),
            $this->row('Meta', 'Tags', null, $db['tags'] ?? [], 'chips', match: null),
        ];

        return array_values(array_filter($rows, function (array $row): bool {
            return ! $this->isEmptyValue($row['fal']) || ! $this->isEmptyValue($row['ours']);
        }));
    }

    /**
     * @return array{group: string, label: string, fal: mixed, ours: mixed, match: ?bool, kind: string}
     */
    protected function row(string $group, string $label, mixed $fal, mixed $ours, string $kind, ?bool $match = true): array
    {
        $computed = $match;
        if ($match === true) {
            $computed = $this->valuesMatch($fal, $ours, $kind);
        }

        return [
            'group' => $group,
            'label' => $label,
            'fal' => $fal,
            'ours' => $ours,
            'match' => $computed,
            'kind' => $kind,
        ];
    }

    /**
     * @return array<string, list<array{group: string, label: string, fal: mixed, ours: mixed, match: ?bool, kind: string}>>
     */
    public function groupedRows(): array
    {
        $grouped = [];
        foreach ($this->rows as $row) {
            $grouped[$row['group'] ?? 'Other'][] = $row;
        }

        return $grouped;
    }

    protected function valuesMatch(mixed $fal, mixed $ours, string $kind): ?bool
    {
        if ($this->isEmptyValue($fal) || $this->isEmptyValue($ours)) {
            return null;
        }

        if ($kind === 'price') {
            return is_numeric($fal) && is_numeric($ours) && abs((float) $fal - (float) $ours) <= 0.000001;
        }

        if ($kind === 'bool') {
            return (bool) $fal === (bool) $ours;
        }

        if ($kind === 'chips') {
            $a = $this->normalizeList($fal);
            $b = $this->normalizeList($ours);
            sort($a);
            sort($b);

            return $a === $b;
        }

        return strtolower(trim((string) $fal)) === strtolower(trim((string) $ours));
    }

    protected function isEmptyValue(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (is_array($value) && $value === []) {
            return true;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            if ($value === null || $value === '') {
                return [];
            }

            return [(string) $value];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $out[] = (string) $item;
            }
        }

        return array_values(array_unique($out));
    }

    public function formatScalar(mixed $value, string $kind = 'text'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if ($kind === 'price' && is_numeric($value)) {
            return number_format((float) $value, 6);
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '—';
    }

    protected function getHeaderActions(): array
    {
        $listClass = match ($this->catalog) {
            'image' => ImageLabModels::class,
            'video' => VideoLabModels::class,
            'voice' => VoiceLabModels::class,
            'music' => MusicLabModels::class,
            default => ImageLabModels::class,
        };

        $endpointId = (string) ($this->dbRow['endpoint_id'] ?? '');

        return [
            Action::make('back')
                ->label('Back')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->url($listClass::getUrl())
                ->color('gray'),
            Action::make('falDocs')
                ->label('Open on Fal')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url($endpointId !== '' ? 'https://fal.ai/models/'.ltrim($endpointId, '/') : '#', true)
                ->visible($endpointId !== ''),
            Action::make('fixAll')
                ->label('Fix all mismatches')
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->action('fixAllMismatches')
                ->color('warning')
                ->visible(fn (): bool => collect($this->issues)->contains(fn ($i) => ($i['fixable'] ?? false) === true))
                ->requiresConfirmation()
                ->modalHeading('Fix all mismatches?')
                ->modalDescription('Writes Fal pricing, status, durations, aspect ratios, and resolutions into this DB model. Lab UI will use the synced lists.'),
            Action::make('toggleRaw')
                ->label(fn (): string => $this->showRaw ? 'Hide raw' : 'Show raw')
                ->icon(Heroicon::OutlinedCodeBracket)
                ->action('toggleRaw')
                ->color('gray'),
            Action::make('refresh')
                ->label('Refresh Fal')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action('refreshFal')
                ->color('primary'),
        ];
    }

    public function getHeading(): string
    {
        return (string) ($this->dbRow['name'] ?? 'Model');
    }

    public function getSubheading(): ?string
    {
        $catalog = match ($this->catalog) {
            'image' => 'Image Lab',
            'video' => 'Video Lab',
            'voice' => 'Voice Lab',
            'music' => 'Music Lab',
            default => 'Models',
        };

        $endpoint = (string) ($this->dbRow['endpoint_id'] ?? '');

        return $endpoint !== '' ? "{$catalog} · {$endpoint}" : $catalog;
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/models/compare/{catalog}/{record}';
    }

    public static function routes(Panel $panel, $configuration = null): void
    {
        $middleware = static::getRouteMiddleware($panel);

        Route::get('/models/compare/{catalog}/{record}', static::class)
            ->middleware($middleware)
            ->whereIn('catalog', ['image', 'video', 'voice', 'music'])
            ->name(static::getRelativeRouteName($panel));
    }

    protected function resolveRecord(string $catalog, int|string $record): ?Model
    {
        $class = match ($catalog) {
            'image' => TextToImageModel::class,
            'video' => TextToVideoModel::class,
            'voice' => TextToVoiceModel::class,
            'music' => TextToMusicModel::class,
            default => null,
        };

        if ($class === null) {
            return null;
        }

        return $class::query()->find($record);
    }

    public function pretty(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'null';
    }

    public function issueTone(string $severity): string
    {
        return match ($severity) {
            'high' => 'fi-color-danger',
            'medium' => 'fi-color-warning',
            default => 'fi-color-info',
        };
    }
}

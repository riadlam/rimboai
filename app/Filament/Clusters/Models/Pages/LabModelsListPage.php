<?php

namespace App\Filament\Clusters\Models\Pages;

use App\Services\FalModelInspector;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use UnitEnum;

abstract class LabModelsListPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|UnitEnum|null $navigationGroup = 'Models';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.clusters.models.pages.lab-models-list';

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    abstract protected function catalogKey(): string;

    public function table(Table $table): Table
    {
        $isImage = $this->catalogKey() === 'image';
        $hasAspectColumns = Schema::hasColumn((new ($this->modelClass()))->getTable(), 'aspect_ratios');

        return $table
            ->query($this->modelClass()::query()->orderBy('sort')->orderBy('name'))
            ->columns([
                TextColumn::make('name')
                    ->label('Model')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Model $record): string => (string) ($record->getAttribute('endpoint_id') ?? ''))
                    ->wrap(),
                TextColumn::make('mismatch')
                    ->label('Mismatch')
                    ->state(fn (Model $record): string => $this->mismatchFor($record)['label'])
                    ->badge()
                    ->color(fn (Model $record): string => $this->mismatchFor($record)['color'])
                    ->tooltip(function (Model $record): ?string {
                        $summary = $this->mismatchFor($record);
                        if (! empty($summary['error'])) {
                            return (string) $summary['error'];
                        }
                        if (($summary['total'] ?? 0) === 0) {
                            return 'In sync with Fal (cached)';
                        }

                        return sprintf(
                            '%d issue(s): %d high, %d medium, %d info — click row to compare',
                            $summary['total'],
                            $summary['high'],
                            $summary['medium'],
                            $summary['info'],
                        );
                    })
                    ->alignCenter()
                    ->sortable(false)
                    ->searchable(false),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->alignCenter()
                    ->color(fn (?string $state): string => match (strtolower((string) $state)) {
                        'active' => 'success',
                        'inactive' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('unit')
                    ->label('Unit')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('unit_price')
                    ->label('Price')
                    ->alignEnd()
                    ->formatStateUsing(fn ($state): string => $state === null || $state === '' ? '—' : number_format((float) $state, 6))
                    ->sortable(),
                TextColumn::make('resolutions')
                    ->label('Resolutions')
                    ->formatStateUsing(function ($state): string {
                        if (! is_array($state) || $state === []) {
                            return 'defaults';
                        }

                        return implode(', ', array_map('strval', $state));
                    })
                    ->wrap()
                    ->visible($hasAspectColumns)
                    ->toggleable(),
                TextColumn::make('aspect_ratios')
                    ->label('Aspects')
                    ->formatStateUsing(function ($state): string {
                        if (! is_array($state) || $state === []) {
                            return 'defaults';
                        }

                        return implode(', ', array_map('strval', $state));
                    })
                    ->wrap()
                    ->visible($hasAspectColumns)
                    ->toggleable(),
                TextColumn::make('max_duration')
                    ->label('Max duration')
                    ->alignCenter()
                    ->placeholder('—')
                    ->visible(! $isImage)
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                    ]),
            ])
            ->headerActions([
                Action::make('scanMismatches')
                    ->label('Scan mismatches')
                    ->icon(Heroicon::OutlinedMagnifyingGlassCircle)
                    ->color('warning')
                    ->action('scanMismatches')
                    ->requiresConfirmation()
                    ->modalHeading('Scan Fal mismatches?')
                    ->modalDescription('Fetches Fal pricing/OpenAPI for every model in this lab (cached ~15 min). First run can take a minute.'),
            ])
            ->recordUrl(fn (Model $record): string => CompareModel::getUrl([
                'catalog' => $this->catalogKey(),
                'record' => $record->getKey(),
            ]))
            ->recordActions([
                Action::make('compare')
                    ->label('Compare')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->url(fn (Model $record): string => CompareModel::getUrl([
                        'catalog' => $this->catalogKey(),
                        'record' => $record->getKey(),
                    ])),
                Action::make('openFal')
                    ->label('Fal')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Model $record): string => 'https://fal.ai/models/'.ltrim((string) $record->getAttribute('endpoint_id'), '/'), true)
                    ->visible(fn (Model $record): bool => filled($record->getAttribute('endpoint_id'))),
            ])
            ->defaultSort('sort')
            ->striped()
            ->paginated([25, 50, 100])
            ->extremePaginationLinks()
            ->emptyStateHeading('No models in this lab')
            ->emptyStateDescription('Sync or seed models to compare them with Fal.');
    }

    /**
     * @return array{ok: bool, error?: string, total: int, high: int, medium: int, info: int, label: string, color: string}
     */
    protected function mismatchFor(Model $record): array
    {
        static $memo = [];

        $key = $this->catalogKey().':'.$record->getKey();
        if (isset($memo[$key])) {
            return $memo[$key];
        }

        /** @var FalModelInspector $inspector */
        $inspector = app(FalModelInspector::class);
        $memo[$key] = $inspector->mismatchSummary(
            (string) $record->getAttribute('endpoint_id'),
            $record->toArray(),
            $this->catalogKey(),
            fresh: false,
            fetchIfMissing: false,
        );

        return $memo[$key];
    }

    public function scanMismatches(): void
    {
        /** @var FalModelInspector $inspector */
        $inspector = app(FalModelInspector::class);
        $models = $this->modelClass()::query()
            ->whereNotNull('endpoint_id')
            ->where('endpoint_id', '!=', '')
            ->orderBy('sort')
            ->orderBy('name')
            ->get();

        $scanned = 0;
        $withIssues = 0;

        foreach ($models as $model) {
            $summary = $inspector->mismatchSummary(
                (string) $model->getAttribute('endpoint_id'),
                $model->toArray(),
                $this->catalogKey(),
                fresh: true,
            );
            $scanned++;
            if (($summary['total'] ?? 0) > 0 || ! ($summary['ok'] ?? false)) {
                $withIssues++;
            }
        }

        Notification::make()
            ->title('Mismatch scan complete')
            ->body("Checked {$scanned} models · {$withIssues} need attention")
            ->success()
            ->send();
    }

    public function getTitle(): string
    {
        return static::$title ?? 'Lab models';
    }

    public function getHeading(): string
    {
        return static::$navigationLabel ?? $this->getTitle();
    }

    public function getSubheading(): ?string
    {
        return 'Mismatch badges use cached Fal data — click Scan mismatches to refresh, or open a row to compare.';
    }
}

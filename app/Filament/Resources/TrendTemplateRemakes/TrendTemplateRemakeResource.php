<?php

namespace App\Filament\Resources\TrendTemplateRemakes;

use App\Filament\Resources\TrendTemplateRemakes\Pages\ManageTrendTemplateRemakes;
use App\Models\TrendTemplate;
use App\Models\UserVideoCreation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Placeholder;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

class TrendTemplateRemakeResource extends Resource
{
    protected static ?string $model = UserVideoCreation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static string|UnitEnum|null $navigationGroup = 'Trends';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Template Remakes';

    protected static ?string $modelLabel = 'Template Remake';

    protected static ?string $pluralModelLabel = 'Template Remakes';

    protected static ?string $slug = 'trend-template-remakes';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->with(['user:id,name,email,avatar'])
            ->where(function (Builder $query): void {
                $query->where('mode', 'trend_template')
                    ->orWhereNotNull('settings->from_trend_template_id');
            })
            ->orderByDesc('id');
    }

    public static function form(Schema $schema): Schema
    {
        // Read-only resource — form unused; view modal shows detail.
        return $schema->components([
            Placeholder::make('info')
                ->content('Open a remake from the table to inspect sheets and results.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->description(fn (UserVideoCreation $record): ?string => $record->user?->email),
                TextColumn::make('template')
                    ->label('Template')
                    ->getStateUsing(function (UserVideoCreation $record): string {
                        $settings = is_array($record->settings) ? $record->settings : [];
                        $slug = trim((string) ($settings['trend_template_slug'] ?? ''));
                        if ($slug !== '') {
                            return $slug;
                        }
                        $id = $settings['from_trend_template_id'] ?? null;

                        return $id ? '#'.$id : '—';
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function (Builder $q) use ($search): void {
                            $q->where('settings->trend_template_slug', 'like', "%{$search}%")
                                ->orWhere('settings->from_trend_template_id', $search);
                        });
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed', 'cancelled' => 'danger',
                        'queued', 'pending' => 'warning',
                        'in_progress' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                ImageColumn::make('face_photos')
                    ->label('Faces')
                    ->circular()
                    ->stacked()
                    ->limit(3)
                    ->getStateUsing(function (UserVideoCreation $record): array {
                        $urls = [];
                        foreach (is_array($record->input_assets) ? $record->input_assets : [] as $asset) {
                            if (! is_array($asset)) {
                                continue;
                            }
                            $role = (string) ($asset['role'] ?? '');
                            if (! str_starts_with($role, 'face_') && $role !== 'reference') {
                                continue;
                            }
                            $url = $asset['fal_url'] ?? $asset['url'] ?? null;
                            if (is_string($url) && $url !== '') {
                                $urls[] = $url;
                            }
                        }

                        return $urls;
                    }),
                ImageColumn::make('character_sheets')
                    ->label('Sheets')
                    ->stacked()
                    ->limit(3)
                    ->getStateUsing(function (UserVideoCreation $record): array {
                        $settings = is_array($record->settings) ? $record->settings : [];
                        $sheets = is_array($settings['character_sheets'] ?? null) ? $settings['character_sheets'] : [];
                        $urls = [];
                        foreach ($sheets as $sheet) {
                            if (! is_array($sheet)) {
                                continue;
                            }
                            $url = $sheet['sheet_url'] ?? null;
                            if (is_string($url) && $url !== '') {
                                $urls[] = $url;
                            }
                        }

                        return $urls;
                    }),
                ImageColumn::make('thumbnail_url')
                    ->label('Result')
                    ->getStateUsing(fn (UserVideoCreation $record): ?string => $record->thumbnail_url
                        ?: $record->result_preview_url
                        ?: null)
                    ->height(48),
                TextColumn::make('credits_charged')
                    ->label('Tokens')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('progress_message')
                    ->label('Progress')
                    ->limit(36)
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'queued' => 'Queued',
                        'in_progress' => 'In progress',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                    ]),
                SelectFilter::make('template')
                    ->label('Template')
                    ->options(fn (): array => TrendTemplate::query()
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }

                        return $query->where('settings->from_trend_template_id', (int) $value);
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('inspect')
                    ->label('Inspect')
                    ->icon(Heroicon::OutlinedEye)
                    ->modalHeading(fn (UserVideoCreation $record): string => 'Remake #'.$record->id)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl')
                    ->modalContent(fn (UserVideoCreation $record) => view(
                        'filament.trend-template-remakes.view',
                        ['record' => $record->loadMissing('user:id,name,email')],
                    )),
                Action::make('openVideo')
                    ->label('Video')
                    ->icon(Heroicon::OutlinedFilm)
                    ->url(fn (UserVideoCreation $record): ?string => $record->result_video_url ?: $record->result_preview_url)
                    ->openUrlInNewTab()
                    ->visible(fn (UserVideoCreation $record): bool => filled($record->result_video_url ?: $record->result_preview_url)),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTrendTemplateRemakes::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}

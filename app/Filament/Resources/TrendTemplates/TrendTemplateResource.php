<?php

namespace App\Filament\Resources\TrendTemplates;

use App\Filament\Resources\TrendTemplates\Pages\ManageTrendTemplates;
use App\Models\TrendTemplate;
use App\Services\FalService;
use App\Services\HiggsfieldService;
use App\Services\TrendTemplateCostEstimator;
use App\Services\TrendTemplateRemakeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;
use UnitEnum;

class TrendTemplateResource extends Resource
{
    protected static ?string $model = TrendTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static string|UnitEnum|null $navigationGroup = 'Trends';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Trend Templates';

    protected static ?string $modelLabel = 'Trend Template';

    protected static ?string $pluralModelLabel = 'Trend Templates';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Set $set, ?string $state, Get $get): void {
                        if (filled($get('slug')) || ! filled($state)) {
                            return;
                        }
                        $set('slug', Str::slug($state));
                    }),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Textarea::make('description')
                    ->rows(2)
                    ->columnSpanFull(),
                FileUpload::make('cover_url')
                    ->label('Cover image')
                    ->image()
                    ->disk('public')
                    ->directory('trend-templates/covers')
                    ->visibility('public')
                    ->imageEditor()
                    ->columnSpanFull(),
                Toggle::make('is_published')
                    ->label('Published')
                    ->default(false),
                Toggle::make('is_featured')
                    ->label('Featured')
                    ->default(true),
                TextInput::make('sort_order')
                    ->numeric()
                    ->default(0)
                    ->required(),
                Select::make('endpoint_id')
                    ->label('Video model (R2V)')
                    ->options(fn (): array => static::r2vEndpointOptions())
                    ->default(TrendTemplate::DEFAULT_ENDPOINT)
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        if (! TrendTemplateRemakeService::isH3SplitEndpoint($state)) {
                            return;
                        }

                        $set('model_name', static::r2vEndpointOptions()[$state] ?? 'MiniMax H3 split + audio (Sogni-style)');
                        $set('prompt', TrendTemplate::adaptPromptForH3Split((string) ($get('prompt') ?? '')));

                        $slots = $get('slots');
                        if (is_array($slots)) {
                            $imageIndex = 0;
                            foreach ($slots as $key => $slot) {
                                if (! is_array($slot)) {
                                    continue;
                                }
                                $imageIndex++;
                                $hint = (string) ($slot['hint'] ?? '');
                                $hint = preg_replace('/character sheet/i', 'reference photo', $hint) ?? $hint;
                                if ($hint === '' || str_contains(strtolower($hint), '@image')) {
                                    $side = str_contains((string) ($slot['role'] ?? ''), 'left') ? 'LEFT' : 'RIGHT';
                                    if (($slot['role'] ?? '') === 'extra') {
                                        $side = 'extra';
                                    }
                                    $hint = "Upload a clear identity/outfit reference (your own sheet OK). Becomes @Image{$imageIndex}".($side !== 'extra' ? " ({$side} performer)." : '.');
                                }
                                $slots[$key]['hint'] = $hint;
                            }
                            $set('slots', $slots);
                        }

                        Notification::make()
                            ->title('Prompt adapted for MiniMax H3')
                            ->body('Kept your creative direction, swapped “character sheet” → uploaded reference photos, and added @Audio1 for lip-sync. No auto sheet generation.')
                            ->success()
                            ->send();
                    })
                    ->helperText('Genjutsu = one-shot silent motion transfer. MiniMax H3 split = camera-cut sections + FlashVSR + original song mux. Selecting H3 adapts the prompt automatically.')
                    ->columnSpanFull(),
                TextInput::make('model_name')
                    ->label('Display model name')
                    ->placeholder('Seedance 2.5')
                    ->maxLength(255),
                FileUpload::make('motion_sketch')
                    ->label('Locked motion sketch (video)')
                    ->acceptedFileTypes(['video/mp4', 'video/webm', 'video/quicktime', 'video/*'])
                    ->disk('public')
                    ->directory('trend-templates/sketches')
                    ->visibility('public')
                    ->required()
                    ->helperText('Motion reference. H3 split auto-cuts into ≤14.5s sections on camera changes. Prefer a sketch that still has the song audio (or upload locked audio below).')
                    ->columnSpanFull(),
                FileUpload::make('locked_audio')
                    ->label('Song audio (required for H3 lip-sync mux)')
                    ->acceptedFileTypes(['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/*'])
                    ->disk('public')
                    ->directory('trend-templates/audio')
                    ->visibility('public')
                    ->helperText('For MiniMax H3: used as per-section Audio 1 refs + final mux. If empty, audio is extracted from the motion sketch. Unused for Genjutsu.')
                    ->columnSpanFull(),
                Repeater::make('slots')
                    ->label('Client face slots')
                    ->schema([
                        TextInput::make('key')
                            ->required()
                            ->maxLength(64)
                            ->default(fn () => 'face_'.Str::lower(Str::random(4))),
                        TextInput::make('label')
                            ->required()
                            ->maxLength(120),
                        Select::make('role')
                            ->options([
                                'body_right' => 'Person on the right (@ImageN)',
                                'body_left' => 'Person on the left (@ImageN)',
                                'extra' => 'Extra person',
                            ])
                            ->default('extra')
                            ->required(),
                        TextInput::make('hint')
                            ->maxLength(255),
                        Toggle::make('required')
                            ->default(true),
                        TextInput::make('kind')
                            ->default('image')
                            ->dehydrated()
                            ->hidden(),
                        TextInput::make('accept')
                            ->default('image/*')
                            ->dehydrated()
                            ->hidden(),
                    ])
                    ->default(TrendTemplate::defaultSlots())
                    ->minItems(1)
                    ->reorderable()
                    ->collapsible()
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                    ->columnSpanFull(),
                Textarea::make('prompt')
                    ->label('Model prompt (locked)')
                    ->rows(12)
                    ->required()
                    ->default(TrendTemplate::defaultPromptScaffold())
                    ->hintAction(
                        Action::make('insertScaffold')
                            ->label('Insert role scaffold')
                            ->action(function (Set $set): void {
                                $set('prompt', TrendTemplate::defaultPromptScaffold());
                            }),
                    )
                    ->helperText('Reference Image 1… / Video 1 / Audio 1. For H3 lip-sync, include verse lyrics marked by who sings each line.')
                    ->columnSpanFull(),
                Select::make('aspect_ratio')
                    ->options([
                        '16:9' => '16:9',
                        '9:16' => '9:16',
                        '1:1' => '1:1',
                        '4:3' => '4:3',
                        '3:4' => '3:4',
                    ])
                    ->default('16:9')
                    ->required(),
                Select::make('resolution')
                    ->options([
                        '480p' => '480p',
                        '720p' => '720p',
                        '1080p' => '1080p',
                    ])
                    ->default('720p')
                    ->required(),
                TextInput::make('duration')
                    ->label('Duration (seconds)')
                    ->default('15')
                    ->required()
                    ->helperText('Used for fal cost estimate / Seedance duration.'),
                Toggle::make('generate_audio')
                    ->label('Generate audio')
                    ->helperText('Off for trends (lighter, no song IP). Users add the track in TikTok/Reels.')
                    ->default(false),
                TextInput::make('fal_estimate_usd')
                    ->label('Provider estimate (USD)')
                    ->numeric()
                    ->step(0.000001)
                    ->readOnly()
                    ->suffixAction(
                        Action::make('estimateFalCost')
                            ->icon(Heroicon::OutlinedCalculator)
                            ->label('Estimate')
                            ->action(function (Get $get, Set $set): void {
                                $estimate = app(TrendTemplateCostEstimator::class)->estimate([
                                    'endpoint_id' => $get('endpoint_id'),
                                    'sheet_endpoint_id' => $get('sheet_endpoint_id'),
                                    'duration' => $get('duration'),
                                    'resolution' => $get('resolution'),
                                    'aspect_ratio' => $get('aspect_ratio'),
                                    'generate_audio' => (bool) $get('generate_audio'),
                                    'slots' => $get('slots'),
                                ]);
                                $set('fal_estimate_usd', $estimate['fal_estimate_usd']);
                                if ((int) ($get('trend_cost') ?? 0) <= 0 && $estimate['suggested_trend_cost'] > 0) {
                                    $set('trend_cost', $estimate['suggested_trend_cost']);
                                }
                                $upscale = (float) ($estimate['upscale_usd'] ?? 0);
                                if ($upscale > 0) {
                                    $body = sprintf(
                                        'Provider ≈ $%s (H3 $%s + FlashVSR $%s). Suggested tokens: %d',
                                        number_format($estimate['fal_estimate_usd'], 4),
                                        number_format($estimate['video_usd'], 4),
                                        number_format($upscale, 4),
                                        $estimate['suggested_trend_cost'],
                                    );
                                } elseif ((float) $estimate['sheets_usd'] > 0) {
                                    $body = sprintf(
                                        'Provider ≈ $%s (video $%s + sheets $%s). Suggested tokens: %d',
                                        number_format($estimate['fal_estimate_usd'], 4),
                                        number_format($estimate['video_usd'], 4),
                                        number_format($estimate['sheets_usd'], 4),
                                        $estimate['suggested_trend_cost'],
                                    );
                                } else {
                                    $body = sprintf(
                                        'Provider ≈ $%s (video $%s, no sheets). Suggested tokens: %d',
                                        number_format($estimate['fal_estimate_usd'], 4),
                                        number_format($estimate['video_usd'], 4),
                                        $estimate['suggested_trend_cost'],
                                    );
                                }
                                Notification::make()
                                    ->title('Estimate ready')
                                    ->body($body)
                                    ->success()
                                    ->send();
                            }),
                    ),
                TextInput::make('trend_cost')
                    ->label('Tokens charged to users')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText('Admin override. Estimate suggests a value; you can change it.'),
                Select::make('sheet_endpoint_id')
                    ->label('Character sheet model')
                    ->helperText('Only for fal R2V that still run sheets (e.g. Seedance). Hidden/unused for Genjutsu and MiniMax H3 — users upload reference photos (sheets) themselves.')
                    ->options([
                        'fal-ai/nano-banana-pro/edit' => 'Nano Banana Pro Edit',
                        'fal-ai/nano-banana/edit' => 'Nano Banana Edit',
                        'fal-ai/nano-banana-2/edit' => 'Nano Banana 2 Edit',
                    ])
                    ->default(TrendTemplate::DEFAULT_SHEET_ENDPOINT)
                    ->required(fn (Get $get): bool => static::endpointUsesCharacterSheets($get('endpoint_id')))
                    ->visible(fn (Get $get): bool => static::endpointUsesCharacterSheets($get('endpoint_id')))
                    ->dehydrated()
                    ->columnSpanFull(),
                Textarea::make('sheet_prompt')
                    ->label('Character sheet prompt')
                    ->rows(8)
                    ->default(TrendTemplate::defaultSheetPrompt())
                    ->required(fn (Get $get): bool => static::endpointUsesCharacterSheets($get('endpoint_id')))
                    ->visible(fn (Get $get): bool => static::endpointUsesCharacterSheets($get('endpoint_id')))
                    ->dehydrated()
                    ->hintAction(static::testSheetFormAction())
                    ->helperText('Use “Test sheet” to run only the character-sheet step (no video, no user tokens).')
                    ->columnSpanFull(),
            ]);
    }

    public static function endpointUsesCharacterSheets(?string $endpointId): bool
    {
        if (HiggsfieldService::isHiggsfieldEndpoint($endpointId)) {
            return false;
        }
        if (TrendTemplateRemakeService::isH3SplitEndpoint($endpointId)) {
            return false;
        }

        return true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('cover_url')
                    ->label('Cover')
                    ->disk('public')
                    ->circular(false)
                    ->height(48),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('endpoint_id')
                    ->label('Model')
                    ->limit(36)
                    ->toggleable(),
                TextColumn::make('trend_cost')
                    ->label('Tokens')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('fal_estimate_usd')
                    ->label('Fal $')
                    ->numeric(decimalPlaces: 4)
                    ->toggleable(),
                TextColumn::make('uses_count')
                    ->label('Uses')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->sortable(),
                IconColumn::make('is_featured')
                    ->boolean()
                    ->label('Featured'),
                IconColumn::make('is_published')
                    ->boolean()
                    ->label('Published'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                static::testSheetRecordAction(),
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data): array => static::mutateRecordDataForForm($data))
                    ->mutateDataUsing(fn (array $data): array => static::mutateFormDataForSave($data)),
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
            'index' => ManageTrendTemplates::route('/'),
        ];
    }

    /**
     * Test sheet from the create/edit form (uses current prompt + endpoint fields).
     */
    public static function testSheetFormAction(): Action
    {
        return Action::make('testSheetFromForm')
            ->label('Test sheet')
            ->icon(Heroicon::OutlinedBeaker)
            ->modalHeading('Test character sheet')
            ->modalDescription('Runs only the sheet model so you can tune the prompt. No video, no user charge.')
            ->modalSubmitActionLabel('Generate sheet')
            ->modalWidth('3xl')
            ->form([
                FileUpload::make('photo')
                    ->label('Test face photo')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->disk('public')
                    ->directory('trend-templates/sheet-tests')
                    ->visibility('public')
                    ->required(),
                Select::make('sheet_endpoint_id')
                    ->label('Sheet model')
                    ->options([
                        'fal-ai/nano-banana-pro/edit' => 'Nano Banana Pro Edit',
                        'fal-ai/nano-banana/edit' => 'Nano Banana Edit',
                        'fal-ai/nano-banana-2/edit' => 'Nano Banana 2 Edit',
                    ])
                    ->required(),
                Textarea::make('sheet_prompt')
                    ->label('Sheet prompt for this test')
                    ->rows(8)
                    ->required(),
            ])
            ->fillForm(function (Get $get): array {
                return [
                    'sheet_endpoint_id' => $get('sheet_endpoint_id') ?: TrendTemplate::DEFAULT_SHEET_ENDPOINT,
                    'sheet_prompt' => $get('sheet_prompt') ?: TrendTemplate::defaultSheetPrompt(),
                ];
            })
            ->action(function (array $data, Get $get, Set $set): void {
                $result = static::runSheetTest(
                    photo: $data['photo'] ?? null,
                    sheetEndpoint: (string) ($data['sheet_endpoint_id'] ?? $get('sheet_endpoint_id')),
                    sheetPrompt: (string) ($data['sheet_prompt'] ?? $get('sheet_prompt')),
                );

                // Keep the prompt that worked in the main form for easy save.
                if (filled($data['sheet_prompt'] ?? null)) {
                    $set('sheet_prompt', $data['sheet_prompt']);
                }
                if (filled($data['sheet_endpoint_id'] ?? null)) {
                    $set('sheet_endpoint_id', $data['sheet_endpoint_id']);
                }

                static::notifySheetTestResult($result);
            });
    }

    /**
     * Test sheet from a saved template row (table action).
     */
    public static function testSheetRecordAction(): Action
    {
        return Action::make('testSheet')
            ->label('Test sheet')
            ->icon(Heroicon::OutlinedBeaker)
            ->color('gray')
            ->modalHeading(fn (TrendTemplate $record): string => 'Test sheet · '.$record->title)
            ->modalDescription('Runs only the character-sheet step. No Seedance video, no user tokens.')
            ->modalSubmitActionLabel('Generate sheet')
            ->modalWidth('3xl')
            ->form([
                FileUpload::make('photo')
                    ->label('Test face photo')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->disk('public')
                    ->directory('trend-templates/sheet-tests')
                    ->visibility('public')
                    ->required(),
                Select::make('sheet_endpoint_id')
                    ->label('Sheet model')
                    ->options([
                        'fal-ai/nano-banana-pro/edit' => 'Nano Banana Pro Edit',
                        'fal-ai/nano-banana/edit' => 'Nano Banana Edit',
                        'fal-ai/nano-banana-2/edit' => 'Nano Banana 2 Edit',
                    ])
                    ->required(),
                Textarea::make('sheet_prompt')
                    ->label('Sheet prompt for this test')
                    ->rows(8)
                    ->required()
                    ->helperText('Edits here are for this test only unless you copy them back into Edit.'),
            ])
            ->fillForm(fn (TrendTemplate $record): array => [
                'sheet_endpoint_id' => $record->sheet_endpoint_id ?: TrendTemplate::DEFAULT_SHEET_ENDPOINT,
                'sheet_prompt' => $record->sheet_prompt ?: TrendTemplate::defaultSheetPrompt(),
            ])
            ->action(function (array $data, TrendTemplate $record): void {
                $result = static::runSheetTest(
                    photo: $data['photo'] ?? null,
                    sheetEndpoint: (string) ($data['sheet_endpoint_id'] ?? $record->sheet_endpoint_id),
                    sheetPrompt: (string) ($data['sheet_prompt'] ?? $record->sheet_prompt),
                );

                static::notifySheetTestResult($result);
            });
    }

    /**
     * @return array{sheet_url: string, endpoint_id: string, prompt: string, photo_url: string}
     */
    public static function runSheetTest(mixed $photo, ?string $sheetEndpoint, ?string $sheetPrompt): array
    {
        $fal = app(FalService::class);
        if (! $fal->configured()) {
            throw ValidationException::withMessages([
                'photo' => 'FAL_KEY is not configured.',
            ]);
        }

        try {
            $photoUrl = static::resolveTestPhotoUrl($photo, $fal);
            return app(TrendTemplateRemakeService::class)->buildCharacterSheet(
                $photoUrl,
                $sheetEndpoint,
                $sheetPrompt,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'photo' => $e->getMessage() !== '' ? $e->getMessage() : 'Sheet test failed.',
            ]);
        }
    }

    /**
     * @param  array{sheet_url: string, endpoint_id: string, prompt: string, photo_url: string}  $result
     */
    public static function notifySheetTestResult(array $result): void
    {
        Notification::make()
            ->title('Character sheet ready')
            ->success()
            ->persistent()
            ->body(new HtmlString(
                view('filament.trend-templates.sheet-test-result', [
                    'sheetUrl' => $result['sheet_url'],
                    'photoUrl' => $result['photo_url'],
                    'endpointId' => $result['endpoint_id'],
                ])->render()
            ))
            ->actions([
                Action::make('openSheet')
                    ->label('Open sheet')
                    ->url($result['sheet_url'])
                    ->openUrlInNewTab()
                    ->button(),
            ])
            ->send();
    }

    private static function resolveTestPhotoUrl(mixed $photo, FalService $fal): string
    {
        if ($photo instanceof TemporaryUploadedFile) {
            return $fal->uploadToCdn($photo);
        }

        if (is_array($photo)) {
            $photo = $photo[0] ?? null;
        }

        if (! is_string($photo) || $photo === '') {
            throw ValidationException::withMessages([
                'photo' => 'Upload a face photo to test.',
            ]);
        }

        if (str_starts_with($photo, 'http://') || str_starts_with($photo, 'https://')) {
            return $photo;
        }

        $path = ltrim($photo, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }

        if (! Storage::disk('public')->exists($path)) {
            throw ValidationException::withMessages([
                'photo' => 'Uploaded photo was not found on disk.',
            ]);
        }

        $absolute = Storage::disk('public')->path($path);
        $bytes = file_get_contents($absolute);
        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages([
                'photo' => 'Could not read the uploaded photo.',
            ]);
        }

        $mime = mime_content_type($absolute) ?: 'image/jpeg';

        return $fal->uploadBytesToCdn($bytes, basename($path), $mime);
    }

    /**
     * @return array<string, string>
     */
    public static function r2vEndpointOptions(): array
    {
        $options = [
            TrendTemplate::DEFAULT_ENDPOINT => 'Higgsfield Genjutsu Motion Transfer (recommended)',
            TrendTemplate::SECONDARY_FALLBACK_ENDPOINT => 'MiniMax H3 split + audio (Sogni-style, FlashVSR)',
            TrendTemplate::FALLBACK_ENDPOINT => 'Seedance 2.5 Reference to Video (fal)',
            'fal-ai/kling-video/o3/pro/reference-to-video' => 'Kling O3 Pro Reference to Video',
            'fal-ai/kling-video/o3/standard/reference-to-video' => 'Kling O3 Standard Reference to Video',
            'fal-ai/wan/v2.7/reference-to-video' => 'Wan 2.7 Reference to Video (output max 10s)',
            'bytedance/seedance-2.0/reference-to-video' => 'Seedance 2.0 Reference to Video',
        ];

        foreach (['text_to_video_models', 'image_to_video_models'] as $table) {
            if (! DbSchema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)
                ->where('status', 'active')
                ->where('endpoint_id', 'like', '%reference-to-video%')
                ->orderBy('sort')
                ->get(['endpoint_id', 'name']);
            foreach ($rows as $row) {
                $id = (string) $row->endpoint_id;
                $options[$id] = (string) ($row->name ?: $id);
            }
        }

        $preferredKeys = [
            TrendTemplate::DEFAULT_ENDPOINT,
            TrendTemplate::SECONDARY_FALLBACK_ENDPOINT,
            TrendTemplate::FALLBACK_ENDPOINT,
            'fal-ai/kling-video/o3/pro/reference-to-video',
            'fal-ai/kling-video/o3/standard/reference-to-video',
            'fal-ai/wan/v2.7/reference-to-video',
            'bytedance/seedance-2.0/reference-to-video',
        ];
        $preferred = [];
        foreach ($preferredKeys as $key) {
            if (isset($options[$key])) {
                $preferred[$key] = $options[$key];
                unset($options[$key]);
            }
        }

        return $preferred + $options;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateRecordDataForForm(array $data): array
    {
        $locked = is_array($data['locked_assets'] ?? null) ? $data['locked_assets'] : [];
        $sketch = null;
        $audio = null;
        foreach ($locked as $asset) {
            if (! is_array($asset)) {
                continue;
            }
            $role = (string) ($asset['role'] ?? '');
            $kind = (string) ($asset['kind'] ?? '');
            $path = static::storagePathFromUrl((string) ($asset['url'] ?? ''));
            if ($role === 'motion_sketch' || $kind === 'video') {
                $sketch = $path ?? ($asset['url'] ?? null);
            }
            if ($role === 'audio' || $kind === 'audio') {
                $audio = $path ?? ($asset['url'] ?? null);
            }
        }
        $data['motion_sketch'] = $sketch;
        $data['locked_audio'] = $audio;
        $data['cover_url'] = static::storagePathFromUrl((string) ($data['cover_url'] ?? ''))
            ?? ($data['cover_url'] ?? null);
        if (empty($data['slots'])) {
            $data['slots'] = TrendTemplate::defaultSlots();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateFormDataForSave(array $data): array
    {
        $sketchUrl = static::publicUrlFromUpload($data['motion_sketch'] ?? null);
        $audioUrl = static::publicUrlFromUpload($data['locked_audio'] ?? null);
        $coverUrl = static::publicUrlFromUpload($data['cover_url'] ?? null);

        if (! is_string($sketchUrl) || $sketchUrl === '') {
            throw ValidationException::withMessages([
                'motion_sketch' => 'Motion sketch video is required.',
            ]);
        }

        $slots = is_array($data['slots'] ?? null) ? array_values($data['slots']) : [];
        $imageSlots = array_values(array_filter(
            $slots,
            fn ($s) => is_array($s) && (string) ($s['kind'] ?? 'image') === 'image',
        ));
        if ($imageSlots === []) {
            throw ValidationException::withMessages([
                'slots' => 'Add at least one client image slot.',
            ]);
        }

        $prompt = trim((string) ($data['prompt'] ?? ''));
        if (! str_contains($prompt, '@Video1')) {
            throw ValidationException::withMessages([
                'prompt' => 'Prompt must reference @Video1 (motion sketch).',
            ]);
        }
        foreach (array_keys($imageSlots) as $i) {
            $tag = '@Image'.($i + 1);
            if (! str_contains($prompt, $tag)) {
                throw ValidationException::withMessages([
                    'prompt' => "Prompt must reference {$tag} for slot order.",
                ]);
            }
        }

        $locked = [[
            'key' => 'motion_sketch',
            'kind' => 'video',
            'role' => 'motion_sketch',
            'url' => $sketchUrl,
        ]];
        if (is_string($audioUrl) && $audioUrl !== '') {
            $locked[] = [
                'key' => 'audio',
                'kind' => 'audio',
                'role' => 'audio',
                'url' => $audioUrl,
            ];
        }

        $normalizedSlots = [];
        foreach ($imageSlots as $index => $slot) {
            $normalizedSlots[] = [
                'key' => (string) ($slot['key'] ?? 'face_'.$index),
                'kind' => 'image',
                'label' => (string) ($slot['label'] ?? 'Upload photo'),
                'role' => (string) ($slot['role'] ?? 'extra'),
                'hint' => (string) ($slot['hint'] ?? ''),
                'accept' => (string) ($slot['accept'] ?? 'image/*'),
                'required' => (bool) ($slot['required'] ?? true),
            ];
        }

        unset($data['motion_sketch'], $data['locked_audio']);
        $data['cover_url'] = $coverUrl;
        $data['locked_assets'] = $locked;
        $data['slots'] = $normalizedSlots;
        $data['prompt'] = $prompt;
        if (! filled($data['sheet_prompt'] ?? null)) {
            $data['sheet_prompt'] = TrendTemplate::defaultSheetPrompt();
        }
        if (! filled($data['endpoint_id'] ?? null)) {
            $data['endpoint_id'] = TrendTemplate::DEFAULT_ENDPOINT;
        }
        if (! filled($data['model_name'] ?? null)) {
            $data['model_name'] = static::r2vEndpointOptions()[(string) $data['endpoint_id']] ?? 'Higgsfield Genjutsu Motion Transfer';
        }

        return $data;
    }

    private static function publicUrlFromUpload(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }
        $path = ltrim($value, '/');
        if (str_starts_with($path, 'storage/')) {
            $path = substr($path, strlen('storage/'));
        }
        if (Storage::disk('public')->exists($path)) {
            return url('/storage/'.$path);
        }

        return url('/storage/'.$path);
    }

    private static function storagePathFromUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://') && ! str_starts_with($url, '/')) {
            return ltrim($url, '/');
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }
        if (str_contains($path, '/storage/')) {
            return ltrim((string) Str::after($path, '/storage/'), '/');
        }

        return ltrim($path, '/');
    }
}

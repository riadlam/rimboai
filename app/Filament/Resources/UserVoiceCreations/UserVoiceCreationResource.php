<?php

namespace App\Filament\Resources\UserVoiceCreations;

use App\Filament\Resources\UserVoiceCreations\Pages\ManageUserVoiceCreations;
use App\Models\UserVoiceCreation;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserVoiceCreationResource extends Resource
{
    protected static ?string $model = UserVoiceCreation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMicrophone;

    protected static string|\UnitEnum|null $navigationGroup = 'Creations';

    protected static ?string $navigationLabel = 'Voice';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                TextInput::make('mode')
                    ->required()
                    ->default('text-to-speech'),
                TextInput::make('endpoint_id')
                    ->default(null),
                TextInput::make('model_name')
                    ->default(null),
                Textarea::make('prompt')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('voice_id')
                    ->default(null),
                TextInput::make('voice_name')
                    ->default(null),
                Toggle::make('use_custom_voice')
                    ->required(),
                Textarea::make('input_assets')
                    ->default(null)
                    ->columnSpanFull(),
                Textarea::make('settings')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('status')
                    ->required()
                    ->default('pending'),
                TextInput::make('discarded')
                    ->numeric()
                    ->default(null),
                TextInput::make('fal_request_id')
                    ->default(null),
                TextInput::make('fal_status_url')
                    ->url()
                    ->default(null),
                TextInput::make('fal_response_url')
                    ->url()
                    ->default(null),
                TextInput::make('queue_position')
                    ->numeric()
                    ->default(null),
                TextInput::make('progress_message')
                    ->default(null),
                Textarea::make('result_assets')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('result_preview_url')
                    ->url()
                    ->default(null),
                TextInput::make('result_audio_url')
                    ->url()
                    ->default(null),
                TextInput::make('duration_seconds')
                    ->numeric()
                    ->default(null),
                Textarea::make('error_message')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('error_type')
                    ->default(null),
                TextInput::make('credits_charged')
                    ->numeric()
                    ->default(null),
                TextInput::make('trend_cost')
                    ->numeric()
                    ->default(null)
                    ->prefix('$'),
                TextInput::make('trend_title')
                    ->default(null),
                TextInput::make('fal_wallet_balance_before')
                    ->numeric()
                    ->default(null),
                TextInput::make('fal_wallet_balance_after')
                    ->numeric()
                    ->default(null),
                TextInput::make('deducted_amount_from_main_wallet')
                    ->numeric()
                    ->default(null),
                TextInput::make('cost_usd')
                    ->numeric()
                    ->default(null),
                Toggle::make('is_favorite')
                    ->required(),
                Toggle::make('is_public')
                    ->required(),
                DateTimePicker::make('queued_at'),
                DateTimePicker::make('started_at'),
                DateTimePicker::make('completed_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->searchable(),
                TextColumn::make('mode')
                    ->searchable(),
                TextColumn::make('endpoint_id')
                    ->searchable(),
                TextColumn::make('model_name')
                    ->searchable(),
                TextColumn::make('voice_id')
                    ->searchable(),
                TextColumn::make('voice_name')
                    ->searchable(),
                IconColumn::make('use_custom_voice')
                    ->boolean(),
                TextColumn::make('status')
                    ->searchable(),
                TextColumn::make('discarded')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('fal_request_id')
                    ->searchable(),
                TextColumn::make('fal_status_url')
                    ->searchable(),
                TextColumn::make('fal_response_url')
                    ->searchable(),
                TextColumn::make('queue_position')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('progress_message')
                    ->searchable(),
                TextColumn::make('result_preview_url')
                    ->searchable(),
                TextColumn::make('result_audio_url')
                    ->searchable(),
                TextColumn::make('duration_seconds')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('error_type')
                    ->searchable(),
                TextColumn::make('credits_charged')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('trend_cost')
                    ->money()
                    ->sortable(),
                TextColumn::make('trend_title')
                    ->searchable(),
                TextColumn::make('fal_wallet_balance_before')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('fal_wallet_balance_after')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('deducted_amount_from_main_wallet')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('cost_usd')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_favorite')
                    ->boolean(),
                IconColumn::make('is_public')
                    ->boolean(),
                TextColumn::make('queued_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                ForceDeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageUserVoiceCreations::route('/'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}

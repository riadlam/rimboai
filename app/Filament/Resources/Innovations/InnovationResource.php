<?php

namespace App\Filament\Resources\Innovations;

use App\Filament\Resources\Innovations\Pages\ManageInnovations;
use App\Models\Innovation;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class InnovationResource extends Resource
{
    protected static ?string $model = Innovation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('innovation_category_id')
                    ->required()
                    ->numeric(),
                TextInput::make('slug')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                Textarea::make('prompt')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('media_type')
                    ->required(),
                FileUpload::make('image_url')
                    ->image()
                    ->required(),
                Textarea::make('image_urls')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('video_url')
                    ->url()
                    ->default(null),
                TextInput::make('audio_url')
                    ->url()
                    ->default(null),
                TextInput::make('model_name')
                    ->default(null),
                TextInput::make('endpoint_id')
                    ->default(null),
                TextInput::make('lab_type')
                    ->required(),
                TextInput::make('aspect_ratio')
                    ->default(null),
                TextInput::make('resolution')
                    ->default(null),
                TextInput::make('duration')
                    ->default(null),
                TextInput::make('quantity')
                    ->required()
                    ->numeric()
                    ->default(1),
                Toggle::make('generate_audio'),
                FileUpload::make('image_mode')
                    ->image(),
                TextInput::make('style_prompt')
                    ->default(null),
                Textarea::make('settings')
                    ->default(null)
                    ->columnSpanFull(),
                TextInput::make('sort')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('status')
                    ->required()
                    ->default('active'),
                Toggle::make('is_featured')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('innovation_category_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('media_type')
                    ->searchable(),
                ImageColumn::make('image_url'),
                TextColumn::make('video_url')
                    ->searchable(),
                TextColumn::make('audio_url')
                    ->searchable(),
                TextColumn::make('model_name')
                    ->searchable(),
                TextColumn::make('endpoint_id')
                    ->searchable(),
                TextColumn::make('lab_type')
                    ->searchable(),
                TextColumn::make('aspect_ratio')
                    ->searchable(),
                TextColumn::make('resolution')
                    ->searchable(),
                TextColumn::make('duration')
                    ->searchable(),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('generate_audio')
                    ->boolean(),
                ImageColumn::make('image_mode'),
                TextColumn::make('style_prompt')
                    ->searchable(),
                TextColumn::make('sort')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->searchable(),
                IconColumn::make('is_featured')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
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
            'index' => ManageInnovations::route('/'),
        ];
    }
}

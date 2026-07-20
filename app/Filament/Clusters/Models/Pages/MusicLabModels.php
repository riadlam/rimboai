<?php

namespace App\Filament\Clusters\Models\Pages;

use App\Models\TextToMusicModel;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class MusicLabModels extends LabModelsListPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMusicalNote;

    protected static ?string $navigationLabel = 'Music Lab';

    protected static ?string $title = 'Music Lab';

    protected static ?string $slug = 'models/music-lab';

    protected static ?int $navigationSort = 24;

    protected function modelClass(): string
    {
        return TextToMusicModel::class;
    }

    protected function catalogKey(): string
    {
        return 'music';
    }
}

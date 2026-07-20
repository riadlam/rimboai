<?php

namespace App\Filament\Clusters\Models\Pages;

use App\Models\TextToVideoModel;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class VideoLabModels extends LabModelsListPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static ?string $navigationLabel = 'Video Lab';

    protected static ?string $title = 'Video Lab';

    protected static ?string $slug = 'models/video-lab';

    protected static ?int $navigationSort = 22;

    protected function modelClass(): string
    {
        return TextToVideoModel::class;
    }

    protected function catalogKey(): string
    {
        return 'video';
    }
}

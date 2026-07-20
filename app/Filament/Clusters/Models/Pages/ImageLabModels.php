<?php

namespace App\Filament\Clusters\Models\Pages;

use App\Models\TextToImageModel;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class ImageLabModels extends LabModelsListPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Image Lab';

    protected static ?string $title = 'Image Lab';

    protected static ?string $slug = 'models/image-lab';

    protected static ?int $navigationSort = 21;

    protected function modelClass(): string
    {
        return TextToImageModel::class;
    }

    protected function catalogKey(): string
    {
        return 'image';
    }
}

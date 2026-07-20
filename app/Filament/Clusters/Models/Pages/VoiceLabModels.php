<?php

namespace App\Filament\Clusters\Models\Pages;

use App\Models\TextToVoiceModel;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class VoiceLabModels extends LabModelsListPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMicrophone;

    protected static ?string $navigationLabel = 'Voice Lab';

    protected static ?string $title = 'Voice Lab';

    protected static ?string $slug = 'models/voice-lab';

    protected static ?int $navigationSort = 23;

    protected function modelClass(): string
    {
        return TextToVoiceModel::class;
    }

    protected function catalogKey(): string
    {
        return 'voice';
    }
}

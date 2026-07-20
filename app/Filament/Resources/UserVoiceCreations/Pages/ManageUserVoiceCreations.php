<?php

namespace App\Filament\Resources\UserVoiceCreations\Pages;

use App\Filament\Resources\UserVoiceCreations\UserVoiceCreationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUserVoiceCreations extends ManageRecords
{
    protected static string $resource = UserVoiceCreationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

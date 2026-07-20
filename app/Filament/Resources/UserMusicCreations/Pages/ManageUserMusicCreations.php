<?php

namespace App\Filament\Resources\UserMusicCreations\Pages;

use App\Filament\Resources\UserMusicCreations\UserMusicCreationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUserMusicCreations extends ManageRecords
{
    protected static string $resource = UserMusicCreationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

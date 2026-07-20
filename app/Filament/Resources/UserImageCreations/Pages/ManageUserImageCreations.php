<?php

namespace App\Filament\Resources\UserImageCreations\Pages;

use App\Filament\Resources\UserImageCreations\UserImageCreationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUserImageCreations extends ManageRecords
{
    protected static string $resource = UserImageCreationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

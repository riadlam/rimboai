<?php

namespace App\Filament\Resources\UserVideoCreations\Pages;

use App\Filament\Resources\UserVideoCreations\UserVideoCreationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUserVideoCreations extends ManageRecords
{
    protected static string $resource = UserVideoCreationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

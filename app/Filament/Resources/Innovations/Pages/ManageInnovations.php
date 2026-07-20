<?php

namespace App\Filament\Resources\Innovations\Pages;

use App\Filament\Resources\Innovations\InnovationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInnovations extends ManageRecords
{
    protected static string $resource = InnovationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

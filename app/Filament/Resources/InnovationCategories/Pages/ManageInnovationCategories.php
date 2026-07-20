<?php

namespace App\Filament\Resources\InnovationCategories\Pages;

use App\Filament\Resources\InnovationCategories\InnovationCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageInnovationCategories extends ManageRecords
{
    protected static string $resource = InnovationCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

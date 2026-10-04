<?php

namespace App\Filament\Resources\TrendTemplates\Pages;

use App\Filament\Resources\TrendTemplates\TrendTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTrendTemplates extends ManageRecords
{
    protected static string $resource = TrendTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateFormDataUsing(fn (array $data): array => TrendTemplateResource::mutateFormDataForSave($data)),
        ];
    }
}

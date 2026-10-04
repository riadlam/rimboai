<?php

namespace App\Filament\Resources\TrendTemplateRemakes\Pages;

use App\Filament\Resources\TrendTemplateRemakes\TrendTemplateRemakeResource;
use Filament\Resources\Pages\ManageRecords;

class ManageTrendTemplateRemakes extends ManageRecords
{
    protected static string $resource = TrendTemplateRemakeResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTitle(): string
    {
        return 'Template Remakes';
    }

    public function getSubheading(): ?string
    {
        return 'All user remakes from curated Trend Templates — face photos, character sheets, and generated videos.';
    }
}

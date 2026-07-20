<?php

namespace App\Filament\Clusters\Models;

use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Support\Icons\Heroicon;

class ModelsCluster extends Cluster
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'Models';

    protected static ?int $navigationSort = 5;

    protected static ?string $clusterBreadcrumb = 'Models';

    protected static ?string $slug = 'models';
}

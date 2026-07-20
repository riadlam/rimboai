<?php

namespace App\Filament\Widgets;

use App\Models\Innovation;
use App\Models\Payment;
use App\Models\User;
use App\Models\UserImageCreation;
use App\Models\UserMusicCreation;
use App\Models\UserVideoCreation;
use App\Models\UserVoiceCreation;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $creations =
            UserImageCreation::query()->count()
            + UserVideoCreation::query()->count()
            + UserMusicCreation::query()->count()
            + UserVoiceCreation::query()->count();

        return [
            Stat::make('Users', number_format(User::query()->count()))
                ->description('Registered accounts')
                ->color('success'),
            Stat::make('Creations', number_format($creations))
                ->description('Image / video / music / voice')
                ->color('info'),
            Stat::make('Paid payments', number_format(Payment::query()->where('status', 'paid')->count()))
                ->description(number_format((float) Payment::query()->where('status', 'paid')->sum('amount'), 0).' DZD total')
                ->color('warning'),
            Stat::make('Innovations', number_format(Innovation::query()->count()))
                ->description('Active: '.number_format(Innovation::query()->where('status', 'active')->count()))
                ->color('primary'),
        ];
    }
}

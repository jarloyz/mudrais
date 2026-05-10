<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Inicio';

    protected static string | \UnitEnum | null $navigationGroup = 'Explorar';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.dashboard';
}

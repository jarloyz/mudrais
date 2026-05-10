<?php

namespace App\Filament\Player\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DiscordDashboard extends Page
{
    protected string $view = 'filament.player.pages.discord-dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?int $navigationSort = 1;

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-home';
    }

    public function mount(): void
    {
        Log::debug('[DiscordDashboard@mount] Cargando dashboard del player', [
            'player_id' => Auth::guard('player_web')->id(),
        ]);
    }

    protected function getViewData(): array
    {
        $player = Auth::guard('player_web')->user();

        Log::info('[DiscordDashboard@getViewData] Dashboard renderizado', [
            'player_id'  => $player->id,
            'discord_id' => $player->discord_id,
        ]);

        return compact('player');
    }
}

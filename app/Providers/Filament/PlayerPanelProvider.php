<?php

namespace App\Providers\Filament;

use App\Filament\Player\Auth\DiscordLogin;
use App\Filament\Player\Pages\BotSuccess;
use App\Filament\Player\Pages\DiscordDashboard;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class PlayerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('player')
            ->path('player')
            ->login(DiscordLogin::class)
            ->authGuard('player_web')
            ->authMiddleware([Authenticate::class])
            ->brandName(config('app.name'))
            ->colors(['primary' => Color::Indigo])
            ->pages([
                DiscordDashboard::class,
                BotSuccess::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ]);
    }
}

<?php

namespace App\Filament\Player\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

class BotSuccess extends Page
{
    protected string $view = 'filament.player.pages.bot-success';

    protected static ?string $title = 'Bot Instalado';

    protected static bool $shouldRegisterNavigation = false;

    public function mount(): void
    {
        Log::debug('[BotSuccess@mount] Mostrando pantalla de éxito del bot', [
            'guild_discord_id' => session('guild_discord_id'),
            'was_created'      => session('was_created'),
        ]);
    }
}

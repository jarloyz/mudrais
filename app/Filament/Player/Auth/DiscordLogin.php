<?php

namespace App\Filament\Player\Auth;

use Filament\Auth\Pages\Login;
use Illuminate\Support\Facades\Log;

class DiscordLogin extends Login
{
    public function mount(): void
    {
        Log::debug('[DiscordLogin@mount] Redirigiendo al login de Discord');

        $this->redirect(route('auth.discord.redirect'));
    }
}

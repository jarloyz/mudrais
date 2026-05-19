<?php

namespace Database\Seeders;

use App\Models\DiscordBot;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class DiscordBotSeeder extends Seeder
{
    public function run(): void
    {
        // Leer del mapa de bots ya construido en config/services.php.
        // array_filter ya descartó entradas sin app_id configurado.
        $botsConfig = config('services.discord.bots', []);

        if (empty($botsConfig)) {
            $this->command->warn('No hay bots configurados en services.discord.bots. Verifica DISCORD_APP_ID en .env');
            return;
        }

        foreach ($botsConfig as $appId => $botData) {
            DiscordBot::updateOrCreate(
                ['app_id' => $appId],
                [
                    'slug'      => $botData['slug'],
                    'tier'      => $botData['tier'],
                    'is_active' => true,
                ]
            );

            $this->command->info("✓ Bot '{$botData['slug']}' (tier {$botData['tier']}) sembrado.");

            Log::info('[DiscordBotSeeder] Bot registrado', [
                'slug'   => $botData['slug'],
                'app_id' => $appId,
                'tier'   => $botData['tier'],
            ]);
        }
    }
}

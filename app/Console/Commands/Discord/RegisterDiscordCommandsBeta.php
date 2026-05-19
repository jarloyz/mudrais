<?php

namespace App\Console\Commands\Discord;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RegisterDiscordCommandsBeta extends Command
{
    protected $signature   = 'discord:register-commands-beta';
    protected $description = 'Registra los Slash Commands del bot Beta (Weaver) en la API de Discord';

    public function handle(): int
    {
        $appId = $this->getBetaAppId();
        $token = env('DISCORD_BOT_TOKEN_BETA');

        if (! $appId || ! $token) {
            $this->error('DISCORD_APP_ID_BETA o DISCORD_BOT_TOKEN_BETA no están configurados en .env');
            return self::FAILURE;
        }

        $commands = [
            // /setup-onboarding — admin only
            // Persiste el canal donde el bot beta creará hilos privados de entrevista.
            [
                'name'                      => 'setup-onboarding',
                'description'               => 'Set the private interview channel for this server.',
                'name_localizations'        => ['es-ES' => 'configurar-onboarding'],
                'description_localizations' => ['es-ES' => 'Configura el canal de entrevistas privadas para este servidor.'],
                'type'                      => 1,
                'default_member_permissions' => '16', // Manage Channels — solo admins
            ],
        ];

        Log::info('[RegisterDiscordCommandsBeta] Enviando comandos a Discord', [
            'app_id'        => $appId,
            'command_count' => count($commands),
        ]);

        $this->info('Enviando comandos al bot Beta...');

        $response = Http::withHeaders([
            'Authorization' => 'Bot ' . $token,
            'Content-Type'  => 'application/json',
        ])->put("https://discord.com/api/v10/applications/{$appId}/commands", $commands);

        if ($response->successful()) {
            $this->info('¡Comandos del bot Beta registrados exitosamente!');
            Log::info('[RegisterDiscordCommandsBeta] Comandos registrados', ['response' => $response->json()]);
            return self::SUCCESS;
        }

        $this->error('Error al registrar: ' . $response->body());
        Log::error('[RegisterDiscordCommandsBeta] Error de Discord API', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        return self::FAILURE;
    }

    private function getBetaAppId(): ?string
    {
        // El app_id del bot beta vive en el mapa de bots por app_id.
        // Buscamos el entry cuyo slug es 'beta'.
        foreach (config('services.discord.bots', []) as $appId => $bot) {
            if (($bot['slug'] ?? null) === 'beta') {
                return $appId;
            }
        }

        return null;
    }
}

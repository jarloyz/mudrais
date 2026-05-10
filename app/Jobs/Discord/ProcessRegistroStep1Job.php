<?php

namespace App\Jobs\Discord;

use App\Infrastructure\Ai\Agents\ContentSafetyAgent;
use App\Services\DiscordAuthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Persiste los datos del paso 1 del registro en DB y Redis.
 *
 * Este job se despacha DESPUÉS de que el controller ya respondió a Discord
 * con el modal de step 2 (type:9). El controller NO espera este job.
 */
class ProcessRegistroStep1Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SendsDiscordFollowUp;

    public function __construct(
        public readonly string  $discordId,
        public readonly string  $username,
        public readonly ?string $guildId,
        public readonly array   $step1Data,
        public readonly string  $token,
    ) {
        $this->onQueue('default');
    }

    /** Campos base que se manejan explícitamente — el resto son valores de mutators. */
    private const BASE_FIELDS = ['nombre', 'edad', 'nacionalidad', 'genero', 'about_me'];

    public function handle(DiscordAuthService $authService, ContentSafetyAgent $safetyAgent): void
    {
        Log::info('[ProcessRegistroStep1Job] Iniciando persistencia step1', [
            'discord_id' => $this->discordId,
            'username'   => $this->username,
        ]);

        $player = $authService->authenticate($this->discordId, $this->username, $this->guildId);

        $edad         = (int) ($this->step1Data['edad'] ?? 0);
        $nombre       = trim($this->step1Data['nombre'] ?? '');
        $nacionalidad = trim($this->step1Data['nacionalidad'] ?? '');
        $genero       = trim($this->step1Data['genero'] ?? '');
        $aboutMe      = trim($this->step1Data['about_me'] ?? '');

        // Content safety check on the public bio
        if ($aboutMe !== '' && ! $safetyAgent->check($aboutMe, $player->id)) {
            Log::warning('[ProcessRegistroStep1Job] about_me rechazado por safety', [
                'player_id' => $player->id,
                'about_me'  => $aboutMe,
            ]);
            $this->sendFollowUp(
                $this->token,
                '⚠️ Tu carta de presentación contiene contenido no permitido. Vuelve a registrarte con un texto diferente.',
                [],
                true
            );
            // Delete the cache so it doesn't pass step 1
            Cache::forget("registro_step1_{$this->discordId}");
            return;
        }

        // Normalización básica de género si coincide con las opciones sugeridas
        $generoLower = mb_strtolower($genero);
        if (str_contains($generoLower, 'hombre')) {
            $genero = 'Hombre';
        } elseif (str_contains($generoLower, 'mujer')) {
            $genero = 'Mujer';
        } elseif (str_contains($generoLower, 'no binario')) {
            $genero = 'No binario';
        }

        $player->update(array_filter([
            'age'                => $edad,
            'name'               => $nombre ?: null,
            'nationality'        => $nacionalidad ?: null,
            'gender'             => $genero ?: null,
            'about_me'           => $aboutMe ?: null,
            // Limpiamos orientación sexual si antes tenía algo, o lo dejamos opcional.
            // El usuario pidió CAMBIARLO por género, así que lo seteamos a null si queremos ser estrictos.
            'sexual_orientation' => null,
        ], fn ($v) => $v !== null || $v === null));

        // Hace merge de la nacionalidad sobre el caché existente.
        // El controller ya guardó is_edit en esta misma clave — no sobreescribir.
        $existing = Cache::get("registro_step1_{$this->discordId}", []);
        Cache::put(
            "registro_step1_{$this->discordId}",
            array_merge($existing, ['nationality' => $nacionalidad ?: null]),
            now()->addMinutes(30),
        );

        Log::info('[ProcessRegistroStep1Job] Step1 persistido correctamente', [
            'player_id'    => $player->id,
            'discord_id'   => $this->discordId,
            'age'          => $edad,
        ]);
    }
}

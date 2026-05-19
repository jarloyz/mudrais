<?php

namespace App\Console\Commands;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\GuildMember;
use App\Domains\Community\Models\Player;
use App\Models\DiscordBot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\Uuid;

class BackfillGuildBotAdminCommand extends Command
{
    protected $signature = 'discord:backfill-guild-bot
                            {discord_guild_id : ID del servidor Discord (número)}
                            {bot_slug : Slug del bot a vincular: alpha, beta o gamma}
                            {admin_discord_id : Discord ID del jugador que debe quedar como admin}';

    protected $description = 'Backfill de una guild existente: vincula el bot en guild_bots y asigna rol admin al player indicado.';

    public function handle(): int
    {
        $discordGuildId   = $this->argument('discord_guild_id');
        $botSlug          = $this->argument('bot_slug');
        $adminDiscordId   = $this->argument('admin_discord_id');

        Log::info('[BackfillGuildBotAdminCommand] Inicio', [
            'discord_guild_id' => $discordGuildId,
            'bot_slug'         => $botSlug,
            'admin_discord_id' => $adminDiscordId,
        ]);

        // ── Validar Guild ─────────────────────────────────────────────────────
        $guild = Guild::where('discord_guild_id', $discordGuildId)->first();
        if (! $guild) {
            $this->error("Guild '{$discordGuildId}' no encontrada en la BD.");
            $this->line('Guilds existentes:');
            Guild::all(['id', 'discord_guild_id', 'is_active'])->each(function ($g) {
                $this->line("  • {$g->discord_guild_id}  (activa: " . ($g->is_active ? 'sí' : 'no') . ')');
            });
            return self::FAILURE;
        }

        // ── Validar Bot ───────────────────────────────────────────────────────
        $bot = DiscordBot::where('slug', $botSlug)->first();
        if (! $bot) {
            $this->error("Bot con slug '{$botSlug}' no encontrado. Bots disponibles:");
            DiscordBot::all(['slug', 'tier', 'is_active'])->each(function ($b) {
                $this->line("  • {$b->slug}  tier={$b->tier}  activo=" . ($b->is_active ? 'sí' : 'no'));
            });
            return self::FAILURE;
        }

        // ── Validar Player ────────────────────────────────────────────────────
        $player = Player::where('discord_id', $adminDiscordId)->first();
        if (! $player) {
            $this->error("Player con discord_id '{$adminDiscordId}' no encontrado.");
            return self::FAILURE;
        }

        // ── Resumen y confirmación ────────────────────────────────────────────
        $this->info('Se van a aplicar los siguientes cambios:');
        $this->table(
            ['Campo', 'Valor'],
            [
                ['Guild',        "{$guild->discord_guild_id} (id: {$guild->id})"],
                ['Bot',          "{$bot->slug} tier={$bot->tier} (id: {$bot->id})"],
                ['Admin player', "{$player->username} / discord_id={$player->discord_id}"],
            ]
        );

        if (! $this->confirm('¿Confirmar?', true)) {
            $this->line('Operación cancelada.');
            return self::SUCCESS;
        }

        // ── Aplicar cambios en transacción ────────────────────────────────────
        try {
            DB::transaction(function () use ($guild, $bot, $player) {
                // 1. Vincular bot a guild si no está ya
                $alreadyLinked = DB::table('guild_bots')
                    ->where('guild_id', $guild->id)
                    ->where('discord_bot_id', $bot->id)
                    ->exists();

                if ($alreadyLinked) {
                    $this->warn("Bot '{$bot->slug}' ya estaba vinculado a la guild — se omite.");
                } else {
                    DB::table('guild_bots')->insert([
                        'id'             => (string) Uuid::v7(),
                        'guild_id'       => $guild->id,
                        'discord_bot_id' => $bot->id,
                        'installed_at'   => now(),
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                    $this->info("✓ Bot '{$bot->slug}' vinculado en guild_bots.");
                    Log::info('[BackfillGuildBotAdminCommand] Bot vinculado', [
                        'guild_id' => $guild->id,
                        'bot_id'   => $bot->id,
                    ]);
                }

                // 2. Crear/actualizar membresía con rol admin
                $member = GuildMember::updateOrCreate(
                    ['player_id' => $player->id, 'guild_id' => $guild->id],
                    ['role' => 'admin']
                );

                $action = $member->wasRecentlyCreated ? 'creada' : 'actualizada';
                $this->info("✓ Membresía {$action}: player '{$player->username}' → role=admin.");
                Log::info('[BackfillGuildBotAdminCommand] Membresía admin aplicada', [
                    'guild_id'  => $guild->id,
                    'player_id' => $player->id,
                    'action'    => $action,
                ]);
            });

            $this->info('Backfill completado sin errores.');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error durante el backfill: ' . $e->getMessage());
            Log::error('[BackfillGuildBotAdminCommand] Excepcion', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }
}

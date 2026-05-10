<?php

namespace App\Console\Commands;

use App\Application\Services\QdrantService;
use App\Domains\Narrative\Models\Vault;
use App\Services\Discord\DiscordApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DeleteVaultCommand extends Command
{
    protected $signature = 'vault:delete {vault_id}';
    protected $description = 'Elimina un Vault de Discord, base de datos y Qdrant (Solo para pruebas)';

    public function handle(DiscordApiService $discordApi, QdrantService $qdrant)
    {
        $vaultId = $this->argument('vault_id');
        $vault = Vault::with('guild')->find($vaultId);

        if (!$vault) {
            $this->error("Vault no encontrado en base de datos: {$vaultId}");
            return Command::FAILURE;
        }

        $guildId = $vault->guild->discord_guild_id ?? null;
        $slug = Str::slug($vault->name);

        $this->info("Eliminando Vault: {$vault->name} ({$vaultId})");

        if ($guildId) {
            $this->info("Buscando canales asociados en el servidor {$guildId}...");
            $channels = $discordApi->getGuildChannels($guildId) ?? [];

            $channelsToDelete = [];
            foreach ($channels as $channel) {
                // Buscamos canales de foros (type 15) que coincidan con el nombre
                if (in_array($channel['name'], ["{$slug}-context", "{$slug}-activity"]) && $channel['type'] === 15) {
                    $channelsToDelete[] = $channel['id'];
                }
            }

            // También añadimos el canal de texto principal (que es el ID del vault)
            $channelsToDelete[] = $vaultId;

            foreach (array_unique($channelsToDelete) as $channelIdToDelete) {
                $this->info("Eliminando canal de Discord: {$channelIdToDelete}");
                $discordApi->deleteChannel($channelIdToDelete);
            }
        }

        if ($vault->vault_hub_qdrant_id) {
            $this->info("Eliminando punto de Qdrant: {$vault->vault_hub_qdrant_id}");
            $qdrant->deleteHubPoint($vault->vault_hub_qdrant_id);
        }

        $this->info("Eliminando Vault de la base de datos...");
        $vault->delete();

        $this->info('¡Vault eliminado exitosamente!');

        return Command::SUCCESS;
    }
}

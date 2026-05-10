<?php

namespace App\Services\Discord;

use App\Domains\Community\Models\Guild;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Narrative\Models\Avatar;
use App\Domains\Narrative\Models\Vault;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VaultOnboardingService
{
    public function __construct(
        private DiscordApiService $discordApi
    ) {}

    /**
     * Obtiene los tipos de entidad para el autocomplete de /create.
     *
     * Si se pasa $channelId (= Vault.id = Discord channel ID), filtra los tipos
     * del arquetipo del vault activo en ese canal.
     * Sin channelId retorna todos los tipos activos (fallback).
     *
     * @return list<array{name: string, value: string}>
     */
    public function getEntityTypeSuggestions(string $query, ?string $channelId = null): array
    {
        Log::debug('[VaultOnboardingService@getEntityTypeSuggestions] Buscando sugerencias', [
            'query'      => $query,
            'channel_id' => $channelId,
        ]);

        $archetypeId = null;

        if ($channelId) {
            $vault = Vault::where('discord_channel_id', $channelId)->first();
            if ($vault) {
                $archetypeId = $vault->primaryArchetype()?->id;
                Log::debug('[VaultOnboardingService@getEntityTypeSuggestions] Vault encontrado', [
                    'vault_id'     => $vault->id,
                    'archetype_id' => $archetypeId,
                ]);
            } else {
                Log::debug('[VaultOnboardingService@getEntityTypeSuggestions] Canal no es un Vault registrado', [
                    'channel_id' => $channelId,
                ]);
            }
        }

        return ArchetypeEntityType::query()
            ->where('is_active', true)
            ->when($archetypeId, fn ($q) => $q->where('archetype_id', $archetypeId))
            ->when($query, fn ($q) => $q->where('type_label', 'like', "%{$query}%"))
            ->with('archetype')
            ->orderBy('sort_order')
            ->limit(25)
            ->get()
            ->map(fn ($et) => [
                'name'  => $et->type_label,
                'value' => (string) $et->id,
            ])
            ->toArray();
    }

    /**
     * Sugerencias para el comando /search (partner + entity types).
     */
    public function getSearchTargetSuggestions(string $query, ?string $channelId = null): array
    {
        $suggestions = [];

        if (empty($query) || stripos('partner', $query) !== false || stripos('jugador', $query) !== false) {
            $suggestions[] = ['name' => 'Partner (Búsqueda de Jugador)', 'value' => 'partner'];
        }

        $entityTypes = $this->getEntityTypeSuggestions($query, $channelId);

        return array_slice(array_merge($suggestions, $entityTypes), 0, 25);
    }

    /**
     * Avatars que el jugador ha aceptado usar en el vault actual (tabla avatar_profile).
     * Solo muestra los que él vinculó explícitamente — propios al crearlos, o públicos adoptados.
     *
     * @return list<array{name: string, value: string}>
     */
    public function getAvatarSuggestions(string $query, ?string $channelId, ?string $discordId): array
    {
        Log::debug('[VaultOnboardingService@getAvatarSuggestions] Buscando avatars aceptados', [
            'channel_id' => $channelId,
            'discord_id' => $discordId,
            'query'      => $query,
        ]);

        $vault = $channelId ? Vault::where('discord_channel_id', $channelId)->first() : null;
        if (! $vault) return [];

        $player = \App\Domains\Community\Models\Player::where('discord_id', $discordId)->first();
        if (! $player) return [];

        $archetypeId = $vault->primaryArchetype()?->id;
        $profile     = PlayerArchetypeProfile::where('player_id', $player->id)
            ->where('archetype_id', $archetypeId)
            ->first();

        if (! $profile) return [];

        return $profile->avatars()
            ->where('avatars.vault_id', $vault->id)
            ->when($query, fn($q) => $q->where('avatars.name', 'like', "%{$query}%"))
            ->orderBy('avatars.name')
            ->limit(25)
            ->get(['avatars.id', 'avatars.name'])
            ->map(fn($a) => ['name' => $a->name, 'value' => (string) $a->id])
            ->toArray();
    }

    /**
     * Obtiene los arquetipos para el autocomplete de Discord.
     */
    public function getArchetypeSuggestions(string $query): array
    {
        return Archetype::where('name', 'like', "%{$query}%")
            ->limit(25)
            ->get()
            ->map(fn($a) => [
                'name'  => $a->name,
                'value' => (string) $a->id,
            ])
            ->toArray();
    }

    /**
     * Crea un Vault en Discord y en la Base de Datos de forma atómica.
     */
    public function createVault(array $data): ?Vault
    {
        $guildDiscordId = $data['guild_id'];
        $archetypeId    = $data['archetype_id'];
        $name           = $data['name'];
        $description    = $data['description'];
        $existingChannelId = $data['channel_id'] ?? null;

        $archetype = Archetype::find($archetypeId);
        if (!$archetype) {
            Log::error('[VaultOnboardingService] Arquetipo no encontrado', ['id' => $archetypeId]);
            return null;
        }

        $guild = Guild::where('discord_guild_id', $guildDiscordId)->first();
        if (!$guild) {
            Log::error('[VaultOnboardingService] Guild no encontrada', ['discord_id' => $guildDiscordId]);
            return null;
        }

        // 1. Crear registro inicial en BD para asegurar que la persistencia funciona
        // y obtener un UUID interno antes de tocar Discord.
        $vault = Vault::create([
            'name'        => $name,
            'description' => $description,
            'guild_id'    => $guild->id,
            'status'      => 'initializing', // Estado temporal
            'is_public'   => true,
        ]);

        // Asociar el arquetipo mediante la relación many-to-many
        $vault->archetypes()->attach($archetype->id, [
            'is_primary' => true,
            'guild_id'   => $guild->id,
        ]);

        $createdDiscordChannelIds = [];

        try {
            if ($existingChannelId) {
                // Caso: Usar canal actual (simplificado)
                $channelId = $existingChannelId;
                $contextChannelId = $existingChannelId;
                $activityChannelId = $existingChannelId;

                Log::info('[VaultOnboardingService] Usando canal existente para el Vault', [
                    'channel_id' => $channelId,
                ]);
            } else {
                // Caso legado: Crear categoría y canales (se mantiene por si acaso no llega channel_id)
                // 2. Buscar o crear la categoría del Arquetipo
                $channels = $this->discordApi->getGuildChannels($guildDiscordId) ?? [];
                $categoryId = null;
                $categoryName = $archetype->name;

                foreach ($channels as $c) {
                    if ($c['type'] === 4 && strtolower($c['name']) === strtolower($categoryName)) {
                        $categoryId = $c['id'];
                        break;
                    }
                }

                if (!$categoryId) {
                    $category = $this->discordApi->createChannel($guildDiscordId, [
                        'name' => $categoryName,
                        'type' => 4, // Category channel
                    ]);
                    if (!$category) throw new \RuntimeException("No se pudo crear la categoría {$categoryName}");
                    $categoryId = $category['id'];
                    $createdDiscordChannelIds[] = $categoryId;
                }

                $vaultSlug = Str::slug($name);
                $overwrites = [
                    [
                        'id'    => $guildDiscordId,
                        'type'  => 0, // role
                        'allow' => (string) ((1 << 10) | (1 << 38)),
                        'deny'  => (string) ((1 << 34) | (1 << 35)),
                    ]
                ];

                // 3. Crear canal de texto del Vault
                $channel = $this->discordApi->createChannel($guildDiscordId, [
                    'name' => "{$vaultSlug}-vault",
                    'type' => 0,
                    'topic' => $description,
                    'parent_id' => $categoryId,
                    'permission_overwrites' => $overwrites,
                ]);
                if (!$channel) throw new \RuntimeException("No se pudo crear el canal principal del vault");

                $channelId = $channel['id'];
                $createdDiscordChannelIds[] = $channelId;

                $contextChannelId  = null;
                $activityChannelId = null;
            }

            // 4. Enviar mensaje de bienvenida
            $this->discordApi->sendMessage($channelId, [
                'embeds' => [[
                    'title' => "🏰 Vault: {$name}",
                    'description' => $description,
                    'color' => 0x7289DA,
                    'fields' => [
                        ['name' => 'Arquetipo', 'value' => $archetype->name, 'inline' => true],
                    ],
                ]]
            ]);

            // 6. Actualizar registro en BD con los IDs finales y marcar como activo
            $vault->update([
                'discord_channel_id'          => $channelId,
                'discord_context_channel_id'  => $contextChannelId,
                'discord_activity_channel_id' => $activityChannelId,
                'status'                      => 'active',
            ]);

            return $vault;

        } catch (\Throwable $e) {
            Log::error('[VaultOnboardingService] Error durante onboarding en Discord, realizando rollback', [
                'vault_id' => $vault->id,
                'error'    => $e->getMessage()
            ]);

            // Rollback Discord: Borrar canales creados
            foreach (array_reverse($createdDiscordChannelIds) as $id) {
                $this->discordApi->deleteChannel($id);
            }

            // Rollback BD: Eliminar el registro del vault
            $vault->delete();

            throw $e; // Re-lanzar para que el Job lo capture y notifique
        }
    }
}

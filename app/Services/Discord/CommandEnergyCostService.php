<?php

namespace App\Services\Discord;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\GuildCommandCost;
use Illuminate\Support\Facades\Log;

class CommandEnergyCostService
{
    /**
     * Obtiene el costo de energía de un comando para una guild específica.
     * Prioriza el override configurado en base de datos.
     *
     * @param string $commandName
     * @param Guild $guild
     * @return int
     */
    public function getCost(string $commandName, Guild $guild): int
    {
        Log::debug('[CommandEnergyCostService@getCost] Resolviendo costo de comando', [
            'command_name' => $commandName,
            'guild_id'     => $guild->id,
        ]);

        $override = GuildCommandCost::where([
            'guild_id'     => $guild->id,
            'command_name' => $commandName,
        ])->first();

        if ($override) {
            Log::debug('[CommandEnergyCostService@getCost] Costo resuelto desde override de guild', [
                'command_name' => $commandName,
                'guild_id'     => $guild->id,
                'cost'         => $override->energy_cost,
                'source'       => 'guild_override',
            ]);
            return $override->energy_cost;
        }

        $defaultCost = config('historia.discord_command_energy.' . $commandName, 0);

        Log::debug('[CommandEnergyCostService@getCost] Costo resuelto desde configuración default', [
            'command_name' => $commandName,
            'guild_id'     => $guild->id,
            'cost'         => $defaultCost,
            'source'       => 'config_default',
        ]);

        return $defaultCost;
    }

    /**
     * Establece un override del costo de energía de un comando para una guild.
     *
     * @param Guild $guild
     * @param string $commandName
     * @param int $cost
     * @return GuildCommandCost
     * @throws \InvalidArgumentException Si el costo es menor a 0.
     */
    public function setGuildOverride(Guild $guild, string $commandName, int $cost): GuildCommandCost
    {
        if ($cost < 0) {
            throw new \InvalidArgumentException("El costo de energía no puede ser negativo.");
        }

        $override = GuildCommandCost::updateOrCreate(
            ['guild_id' => $guild->id, 'command_name' => $commandName],
            ['energy_cost' => $cost]
        );

        Log::info('[CommandEnergyCostService@setGuildOverride] Override de costo de comando establecido', [
            'guild_id'     => $guild->id,
            'command_name' => $commandName,
            'cost'         => $cost,
        ]);

        return $override;
    }

    /**
     * Elimina el override del costo de energía de un comando para una guild.
     *
     * @param Guild $guild
     * @param string $commandName
     * @return void
     */
    public function removeGuildOverride(Guild $guild, string $commandName): void
    {
        GuildCommandCost::where([
            'guild_id'     => $guild->id,
            'command_name' => $commandName,
        ])->delete();

        Log::info('[CommandEnergyCostService@removeGuildOverride] Override de costo de comando eliminado', [
            'guild_id'     => $guild->id,
            'command_name' => $commandName,
        ]);
    }
}

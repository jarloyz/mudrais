<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentConfig extends Model
{
    use \App\Traits\HasUuidV7;

    protected $table = 'agent_configs';

    protected $fillable = [
        'scope',
        'name',
        'active',
        'player_id',
        'vault_id',
        'scene_id',
        'provider',
        'writer_model',
        'qa_model',
        'timeout_ms',
        'settings_json',
    ];

    protected $casts = [
        'settings_json' => 'array',
        'timeout_ms'    => 'integer',
        'active'        => 'boolean',
    ];

    // ── Static helpers ───────────────────────────────────────────────────────

    /**
     * Retorna el preset global activo. Si no existe ninguno, crea uno con nombre 'Default'.
     */
    public static function globalInstance(): static
    {
        $active = static::query()
            ->where('scope', 'global')
            ->where('active', true)
            ->first();

        if ($active) {
            return $active;
        }

        // Activar el primero existente o crear uno nuevo
        $first = static::query()->where('scope', 'global')->first();
        if ($first) {
            $first->update(['active' => true, 'name' => $first->name ?? 'Default']);
            return $first->fresh();
        }

        return static::create([
            'scope'  => 'global',
            'name'   => 'Default',
            'active' => true,
        ]);
    }

    /**
     * Todos los presets del scope global, ordenados por nombre.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, static>
     */
    public static function allGlobalPresets(): \Illuminate\Database\Eloquent\Collection
    {
        return static::query()
            ->where('scope', 'global')
            ->orderBy('name')
            ->get();
    }

    /**
     * Marca este preset como activo y desactiva los demás del scope global.
     */
    public function activateAsGlobal(): void
    {
        static::query()->where('scope', 'global')->update(['active' => false]);
        $this->update(['active' => true]);
    }

    /**
     * Un único SELECT con OR conditions sobre índices.
     * Devuelve las filas aplicables ordenadas de menos a más específico
     * (global=0, player=1, vault=2, scene=3) para deep-merge en ese orden.
     *
     * @return Collection<int, static>
     */
    public static function resolveHierarchy(
        ?string $playerId,
        ?string $vaultId,
        ?string $sceneId,
    ): Collection {
        return static::query()
            ->where(function (Builder $q) use ($playerId, $vaultId, $sceneId): void {
                $q->where('scope', 'global')->where('active', true);

                if ($playerId !== null && $playerId !== '') {
                    $q->orWhere(fn (Builder $s) => $s->where('scope', 'player')->where('player_id', $playerId));
                }
                if ($vaultId !== null && $vaultId !== '') {
                    $q->orWhere(fn (Builder $s) => $s->where('scope', 'vault')->where('vault_id', $vaultId));
                }
                if ($sceneId !== null && $sceneId !== '') {
                    $q->orWhere(fn (Builder $s) => $s->where('scope', 'scene')->where('scene_id', $sceneId));
                }
            })
            ->orderByRaw("CASE scope
                WHEN 'global' THEN 0
                WHEN 'player' THEN 1
                WHEN 'vault'  THEN 2
                WHEN 'scene'  THEN 3
                ELSE 9 END")
            ->get();
    }

    // ── Eloquent scopes ──────────────────────────────────────────────────────

    public function scopeGlobal(Builder $query): Builder
    {
        return $query->where('scope', 'global');
    }

    public function scopeForPlayer(Builder $query, string $playerId): Builder
    {
        return $query->where('scope', 'player')->where('player_id', $playerId);
    }

    public function scopeForVault(Builder $query, string $vaultId): Builder
    {
        return $query->where('scope', 'vault')->where('vault_id', $vaultId);
    }

    public function scopeForScene(Builder $query, string $sceneId): Builder
    {
        return $query->where('scope', 'scene')->where('scene_id', $sceneId);
    }

    // ── Relaciones ───────────────────────────────────────────────────────────

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}

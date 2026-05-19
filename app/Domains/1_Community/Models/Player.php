<?php

namespace App\Domains\Community\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;

class Player extends Authenticatable implements FilamentUser, HasName
{
    use HasApiTokens, HasFactory, \App\Traits\HasUuidV7;

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'player';
    }

    public function getFilamentName(): string
    {
        return $this->username;
    }

    protected $fillable = [
        'discord_id',
        'username',
        'name',
        'age',
        'gender',
        'sexual_orientation',
        'country_code',
        'nationality',
        'about_me',
        'energy',
        'coin',
        'elo',
        'last_active_at',
        'is_active',
        'tutorial_completed',
        'global_banned',
        'preferred_locale',
    ];

    protected $attributes = [
        'energy'             => 100,
        'coin'               => 0,
        'elo'                => 1000,
        'is_active'          => true,
        'tutorial_completed' => false,
        'global_banned'      => false,
    ];

    protected $casts = [
        'last_active_at'     => 'datetime',
        'is_active'          => 'boolean',
        'tutorial_completed' => 'boolean',
        'global_banned'      => 'boolean',
        'energy'             => 'integer',
        'coin'               => 'integer',
        'elo'                => 'integer',
        'age'                => 'integer',
    ];

    public function archetypeProfiles(): HasMany
    {
        return $this->hasMany(\App\Domains\Matchmaking\Models\PlayerArchetypeProfile::class);
    }

    public function guilds(): BelongsToMany
    {
        return $this->belongsToMany(Guild::class, 'guild_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function isAdminIn(string $discordGuildId): bool
    {
        Log::debug('[Player@isAdminIn] Verificando rol admin', [
            'player_id'        => $this->id,
            'discord_guild_id' => $discordGuildId,
        ]);
        return $this->guilds()
            ->where('discord_guild_id', $discordGuildId)
            ->wherePivot('role', 'admin')
            ->exists();
    }

    public function getRoleIn(string $discordGuildId): ?string
    {
        return $this->guilds()
            ->where('discord_guild_id', $discordGuildId)
            ->first()
            ?->pivot->role;
    }

    public function guildMembers(): HasMany
    {
        return $this->hasMany(GuildMember::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PlayerTransaction::class);
    }

    /**
     * Descuenta monedas del jugador y registra la transacción de forma atómica.
     *
     * @throws \RuntimeException si el saldo es insuficiente
     */
    public function deductCoins(int $amount, string $description, array $metadata = []): PlayerTransaction
    {
        Log::debug('[Player@deductCoins] Iniciando deducción', [
            'player_id'   => $this->id,
            'amount'      => $amount,
            'description' => $description,
        ]);

        if ($this->coin < $amount) {
            Log::warning('[Player@deductCoins] Saldo insuficiente', [
                'player_id' => $this->id,
                'coin'      => $this->coin,
                'required'  => $amount,
            ]);
            throw new \RuntimeException("Saldo insuficiente: se requieren {$amount} monedas, tienes {$this->coin}.");
        }

        return DB::transaction(function () use ($amount, $description, $metadata) {
            $this->decrement('coin', $amount);
            $this->refresh();

            $transaction = $this->transactions()->create([
                'type'         => 'debit',
                'amount'       => $amount,
                'description'  => $description,
                'balance_after'=> $this->coin,
                'metadata'     => $metadata,
            ]);

            Log::info('[Player@deductCoins] Transacción registrada', [
                'player_id'      => $this->id,
                'transaction_id' => $transaction->id,
                'balance_after'  => $this->coin,
            ]);

            return $transaction;
        });
    }

    /**
     * Descuenta energía de acción del jugador (action points para turnos de rol).
     *
     * @throws \RuntimeException si la energía es insuficiente
     */
    public function deductEnergy(int $amount, string $description, array $metadata = []): void
    {
        Log::debug('[Player@deductEnergy] Iniciando deducción', [
            'player_id'   => $this->id,
            'amount'      => $amount,
            'description' => $description,
        ]);

        if ($this->energy < $amount) {
            Log::warning('[Player@deductEnergy] Energía insuficiente', [
                'player_id' => $this->id,
                'energy'    => $this->energy,
                'required'  => $amount,
            ]);
            throw new \RuntimeException("Energía insuficiente: se requieren {$amount} puntos, tienes {$this->energy}.");
        }

        $this->decrement('energy', $amount);
        $this->refresh();

        Log::info('[Player@deductEnergy] Energía deducida', [
            'player_id'    => $this->id,
            'amount'       => $amount,
            'energy_after' => $this->energy,
        ]);
    }

    /**
     * Acredita monedas al jugador y registra la transacción de forma atómica.
     */
    public function creditCoins(int $amount, string $description, array $metadata = []): PlayerTransaction
    {
        Log::debug('[Player@creditCoins] Iniciando crédito', [
            'player_id'   => $this->id,
            'amount'      => $amount,
            'description' => $description,
        ]);

        return DB::transaction(function () use ($amount, $description, $metadata) {
            $this->increment('coin', $amount);
            $this->refresh();

            $transaction = $this->transactions()->create([
                'type'         => 'credit',
                'amount'       => $amount,
                'description'  => $description,
                'balance_after'=> $this->coin,
                'metadata'     => $metadata,
            ]);

            Log::info('[Player@creditCoins] Transacción registrada', [
                'player_id'      => $this->id,
                'transaction_id' => $transaction->id,
                'balance_after'  => $this->coin,
            ]);

            return $transaction;
        });
    }
}

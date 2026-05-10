<?php

namespace Database\Factories;

use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    protected $model = Player::class;

    public function definition(): array
    {
        return [
            'discord_id' => (string) fake()->unique()->numerify('discord_##########'),
            'username' => fake()->userName(),
            'energy' => 100,
            'coin' => 0,
            'elo' => 1000,
            'is_active' => true,
        ];
    }
}

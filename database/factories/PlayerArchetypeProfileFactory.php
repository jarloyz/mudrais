<?php

namespace Database\Factories;

use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlayerArchetypeProfileFactory extends Factory
{
    protected $model = PlayerArchetypeProfile::class;

    public function definition(): array
    {
        return [
            'discord_user_id' => $this->faker->numerify('##################'),
            'positive_prefs' => [],
            'red_lines' => [],
            'yellow_lines' => [],
            'metadata' => [],
        ];
    }
}

<?php

namespace Database\Factories;

use App\Domains\Matchmaking\Models\Archetype;
use Illuminate\Database\Eloquent\Factories\Factory;

class ArchetypeFactory extends Factory
{
    protected $model = Archetype::class;

    public function definition(): array
    {
        return [
            'id' => $this->faker->unique()->numberBetween(1, 1000),
            'name' => $this->faker->unique()->word(),
            'qdrant_vector_name' => $this->faker->unique()->word(),
        ];
    }
}

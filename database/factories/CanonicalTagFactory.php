<?php

namespace Database\Factories;

use App\Models\CanonicalTag;
use Illuminate\Database\Eloquent\Factories\Factory;

class CanonicalTagFactory extends Factory
{
    protected $model = CanonicalTag::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'slug' => $this->faker->unique()->slug(),
        ];
    }
}

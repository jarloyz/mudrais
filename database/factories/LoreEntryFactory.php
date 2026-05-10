<?php

namespace Database\Factories;

use App\Models\LoreEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoreEntry>
 */
class LoreEntryFactory extends Factory
{
    protected $model = LoreEntry::class;

    public function definition(): array
    {
        return [
            'vault_id'      => 'vault_test',
            'content'       => $this->faker->paragraph(),
            'metadata'      => ['type' => 'canon'],
            'lineage_id'    => null,
            'version_start' => 1,
            'version_end'   => null,
        ];
    }
}

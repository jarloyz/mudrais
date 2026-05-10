<?php

namespace Tests\Support;

trait SeedsPromptTemplates
{
    protected function seedPromptTemplates(): void
    {
        $this->seed(\Database\Seeders\AiPromptTemplatesSeeder::class);
        $this->seed(\Database\Seeders\PromptMigrationSeeder::class);
    }
}

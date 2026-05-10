<?php

namespace Tests\Feature\Catalog;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_tables_exist_after_migration(): void
    {
        $this->assertTrue(Schema::hasTable('vaults'));
        $this->assertTrue(Schema::hasTable('story_contexts'));
        $this->assertTrue(Schema::hasTable('avatars'));
        $this->assertTrue(Schema::hasTable('character_inventory'));
        $this->assertTrue(Schema::hasTable('character_instances'));
        $this->assertTrue(Schema::hasTable('locations'));
        $this->assertTrue(Schema::hasTable('activities'));
        $this->assertTrue(Schema::hasTable('activity_members'));
    }
}

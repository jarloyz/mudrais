<?php

namespace Tests\Unit\Matchmaking;

use App\Domains\Matchmaking\Enums\MutatorStorageMode;
use PHPUnit\Framework\TestCase;

class MutatorStorageModeTest extends TestCase
{
    public function test_stores_raw(): void
    {
        $this->assertTrue(MutatorStorageMode::RAW->storesRaw());
        $this->assertTrue(MutatorStorageMode::BOTH->storesRaw());
        $this->assertTrue(MutatorStorageMode::SEMANTIC->storesRaw());
    }

    public function test_stores_semantic(): void
    {
        $this->assertTrue(MutatorStorageMode::SEMANTIC->storesSemantic());
        $this->assertTrue(MutatorStorageMode::BOTH->storesSemantic());
        $this->assertFalse(MutatorStorageMode::RAW->storesSemantic());
    }

    public function test_options(): void
    {
        $options = MutatorStorageMode::options();
        $this->assertArrayHasKey('raw', $options);
        $this->assertArrayHasKey('semantic', $options);
        $this->assertArrayHasKey('both', $options);
        $this->assertEquals('raw', $options['raw']);
        $this->assertEquals('semantic', $options['semantic']);
        $this->assertEquals('both', $options['both']);
    }
}

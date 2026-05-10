<?php

namespace Tests\Unit\Models;

use App\Domains\Matchmaking\Models\ArchetypeMutator;
use App\Domains\Matchmaking\Enums\MutatorStorageMode;
use PHPUnit\Framework\TestCase;

class ArchetypeMutatorSchemaBuilderTest extends TestCase
{
    public function test_storage_mode_cast(): void
    {
        $mutator = new ArchetypeMutator();
        $mutator->fill([
            'storage_mode' => MutatorStorageMode::SEMANTIC
        ]);

        $this->assertInstanceOf(MutatorStorageMode::class, $mutator->storage_mode);
        $this->assertEquals(MutatorStorageMode::SEMANTIC, $mutator->storage_mode);
    }

    public function test_is_inline(): void
    {
        $mutator = new ArchetypeMutator();
        $mutator->fill([
            'modal_group' => null
        ]);
        $this->assertTrue($mutator->isInline());

        $mutator->fill([
            'modal_group' => 'group1'
        ]);
        $this->assertFalse($mutator->isInline());
    }
}

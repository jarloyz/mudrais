<?php

namespace Tests\Unit\Traits;

use App\Models\Optimizable;
use App\Domains\Community\Models\Player;
use App\Domains\Shared\Traits\HasOptimizedText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DummyOptimizableModel extends \Illuminate\Database\Eloquent\Model
{
    use HasOptimizedText, \App\Traits\HasUuidV7;
    protected $table = 'players';
    protected $guarded = [];
}

class HasOptimizedTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_saves_and_retrieves_optimized_text_polymorphically()
    {
        $dummy = DummyOptimizableModel::create([
            'discord_id' => '123456',
            'username' => 'testuser',
        ]);

        $dummy->saveOptimizedText('Optimized text here.');

        $this->assertEquals('Optimized text here.', $dummy->getOptimizedText());
        $this->assertDatabaseHas('optimizables', [
            'optimizable_type' => DummyOptimizableModel::class,
            'optimizable_id' => $dummy->id,
            'optimized_text' => 'Optimized text here.',
        ]);
    }

    public function test_overwrites_existing_optimized_text()
    {
        $dummy = DummyOptimizableModel::create([
            'discord_id' => '654321',
            'username' => 'testuser2',
        ]);

        $dummy->saveOptimizedText('First version.');
        $dummy->saveOptimizedText('Second version.');

        $this->assertEquals('Second version.', $dummy->getOptimizedText());

        $this->assertEquals(1, Optimizable::where('optimizable_id', $dummy->id)
            ->where('optimizable_type', DummyOptimizableModel::class)
            ->count()
        );
    }
}

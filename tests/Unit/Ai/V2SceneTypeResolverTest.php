<?php

namespace Tests\Unit\Ai;

use App\Domain\Scene\Activity;
use App\Infrastructure\Ai\Prompts\V2SceneTypeResolver;
use PHPUnit\Framework\TestCase;

class V2SceneTypeResolverTest extends TestCase
{
    public function test_resolves_simple_by_default(): void
    {
        $scene = Activity::fromArray([
            'id' => 'scene_1',
            'vaultId' => 'vault_1',
            'draft' => 'draft'
        ]);

        $result = V2SceneTypeResolver::resolveSceneType($scene, [
            'characters' => [],
            'events' => [],
            'stateChanges' => [],
        ]);

        $this->assertSame('simple', $result['sceneType']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_resolves_complex_from_directive(): void
    {
        $scene = Activity::fromArray([
            'id' => 'scene_2',
            'vaultId' => 'vault_1',
            'draft' => 'draft',
            'constraints' => "tipo_escena: complex\nsin romper continuidad"
        ]);

        $result = V2SceneTypeResolver::resolveSceneType($scene, []);

        $this->assertSame('complex', $result['sceneType']);
    }
}

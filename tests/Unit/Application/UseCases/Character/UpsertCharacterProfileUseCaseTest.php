<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCases\Character;

use App\Application\Ports\CharacterRepositoryInterface;
use App\Application\UseCases\Character\UpsertCharacterProfileInput;
use App\Application\UseCases\Character\UpsertCharacterProfileUseCase;
use PHPUnit\Framework\TestCase;

class UpsertCharacterProfileUseCaseTest extends TestCase
{
    public function testExecuteUpsertsCharacterProfile(): void
    {
        $mockCharacterRepository = $this->createMock(CharacterRepositoryInterface::class);
        $mockCharacterRepository->expects($this->once())
            ->method('upsertCharacterProfile')
            ->willReturn([
                'characterId' => 'new-character',
                'contextId' => null,
                'stats' => ['character_upserted' => 1, 'tags_written' => 2, 'traits_upserted' => 1],
            ]);

        $useCase = new UpsertCharacterProfileUseCase($mockCharacterRepository);
        $input = UpsertCharacterProfileInput::fromArray([
            'id' => 'new-character',
            'name' => 'New Avatar',
            'tags' => ['tagA', 'tagB'],
            'traits' => [
                ['key' => 'personality', 'title' => 'Personality', 'bullets' => ['Brave', 'Kind']],
            ],
        ]);
        $result = $useCase->execute($input);

        $this->assertIsArray($result);
        $this->assertEquals('new_character', $result['characterId']);
        $this->assertArrayHasKey('stats', $result);
        $this->assertEquals(1, $result['stats']['character_upserted']);
    }

    public function testExecuteThrowsExceptionForInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        UpsertCharacterProfileInput::fromArray([]);
    }
}

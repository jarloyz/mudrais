<?php

declare(strict_types=1);

namespace Tests\Unit\Application\UseCases\Character;

use App\Application\Ports\CharacterRepositoryInterface;
use App\Application\UseCases\Character\GetCharacterProfileInput;
use App\Application\UseCases\Character\GetCharacterProfileUseCase;
use PHPUnit\Framework\TestCase;

class GetCharacterProfileUseCaseTest extends TestCase
{
    public function testExecuteReturnsCharacterProfile(): void
    {
        $mockCharacterRepository = $this->createMock(CharacterRepositoryInterface::class);
        $mockCharacterRepository->expects($this->once())
            ->method('getCharacterProfile')
            ->with('character-123', null)
            ->willReturn([
                'id' => 'character-123',
                'name' => 'Test Avatar',
                'context_id' => null,
                'tags' => ['tag1', 'tag2'],
                'traits' => [],
            ]);

        $useCase = new GetCharacterProfileUseCase($mockCharacterRepository);
        $input = new GetCharacterProfileInput('character-123', null);
        $result = $useCase->execute($input);

        $this->assertIsArray($result);
        $this->assertEquals('character-123', $result['id']);
        $this->assertEquals('Test Avatar', $result['name']);
    }

    public function testExecuteThrowsExceptionForInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GetCharacterProfileInput('', null);
    }
}

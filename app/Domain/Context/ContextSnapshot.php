<?php

declare(strict_types=1);

namespace App\Domain\Context;

use App\Domain\Scene\Activity;

final class ContextSnapshot
{
    public function __construct(
        public readonly ?Activity $scene,
        public readonly array $characters,
        public readonly array $events,
        public readonly ?string $location,
        public readonly array $stateChanges
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            scene: isset($data['scene']) ? Activity::fromArray($data['scene']) : null,
            characters: self::normalizeList($data['characters'] ?? []),
            events: self::normalizeList($data['events'] ?? []),
            location: self::optionalString($data, 'location'),
            stateChanges: self::normalizeList($data['stateChanges'] ?? [])
        );
    }

    private static function normalizeList(array $items): array
    {
        return array_values(array_filter($items, fn($x) => $x !== null));
    }

    private static function optionalString(array $data, string $field): ?string
    {
        if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
            return null;
        }
        return trim($data[$field]);
    }
}

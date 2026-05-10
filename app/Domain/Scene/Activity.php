<?php

declare(strict_types=1);

namespace App\Domain\Scene;

use InvalidArgumentException;

class Activity
{
    public function __construct(
        public readonly string $id,
        public readonly string $vaultId,
        public readonly ?string $title,
        public readonly int $chapter,
        public readonly int $sceneNumber,
        public readonly string $status,
        public readonly ?string $locationId,
        public readonly ?string $objective,
        public readonly ?string $constraints,
        public readonly string $draft,
        public readonly array $characters = [],
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null
    ) {
        if (empty($id)) {
            throw new InvalidArgumentException('Activity ID is required.');
        }
        if (empty($vaultId)) {
            throw new InvalidArgumentException('Vault ID is required.');
        }
        if ($chapter <= 0) {
            throw new InvalidArgumentException('Activity chapter must be a positive integer.');
        }
        if ($sceneNumber <= 0) {
            throw new InvalidArgumentException('Activity number must be a positive integer.');
        }
        if (empty($status)) {
            throw new InvalidArgumentException('Activity status is required.');
        }
        if (empty($draft)) {
            throw new InvalidArgumentException('Activity draft is required.');
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: self::requiredString($data, 'id'),
            vaultId: self::requiredString($data, 'vaultId'),
            title: self::optionalString($data, 'title'),
            chapter: self::nonNegativeInt($data, 'chapter', 1),
            sceneNumber: self::nonNegativeInt($data, 'sceneNumber', 1),
            status: self::optionalString($data, 'status') ?? 'draft',
            locationId: self::optionalString($data, 'locationId'),
            objective: self::optionalString($data, 'objective'),
            constraints: self::optionalString($data, 'constraints'),
            draft: self::optionalString($data, 'draft') ?? '',
            characters: $data['characters'] ?? [],
            createdAt: self::optionalString($data, 'createdAt'),
            updatedAt: self::optionalString($data, 'updatedAt')
        );
    }

    private static function requiredString(array $data, string $field): string
    {
        if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
            throw new InvalidArgumentException("Activity ${field} is required.");
        }
        return trim($data[$field]);
    }

    private static function optionalString(array $data, string $field): ?string
    {
        if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
            return null;
        }
        return trim($data[$field]);
    }

    private static function nonNegativeInt(array $data, string $field, int $fallback = 0): int
    {
        if (!isset($data[$field]) || !is_numeric($data[$field])) {
            return $fallback;
        }
        $n = (int) $data[$field];
        return max(0, $n);
    }
}

<?php

declare(strict_types=1);

namespace App\Application\UseCases\Character;

use InvalidArgumentException;

final class GetCharacterProfileInput
{
    public function __construct(
        public readonly string $characterId,
        public readonly ?int $contextId
    ) {
        if (empty($characterId)) {
            throw new InvalidArgumentException('Avatar ID is required.');
        }
        if ($contextId !== null && $contextId < 0) {
            throw new InvalidArgumentException('Context ID must be a non-negative integer or null.');
        }
    }

    public static function fromArray(array $data): self
    {
        $characterId = self::requiredString($data, 'characterId', 'id');
        $contextId = self::intOrNull($data, 'contextId', 'context_id');

        return new self($characterId, $contextId);
    }

    private static function requiredString(array $data, string ...$fields): string
    {
        foreach ($fields as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) !== '') {
                return trim($data[$field]);
            }
        }
        throw new InvalidArgumentException('Avatar ID is required.');
    }

    private static function intOrNull(array $data, string ...$fields): ?int
    {
        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $value = $data[$field];
                if ($value === null) {
                    return null;
                }
                if (is_numeric($value) && is_finite((float) $value)) {
                    $n = (int) $value;
                    return max(0, $n);
                }
            }
        }
        return null;
    }
}

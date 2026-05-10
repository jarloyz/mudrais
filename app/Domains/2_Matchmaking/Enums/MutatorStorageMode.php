<?php

namespace App\Domains\Matchmaking\Enums;

enum MutatorStorageMode: string
{
    case RAW = 'raw';
    case SEMANTIC = 'semantic';
    case BOTH = 'both';

    public function storesRaw(): bool
    {
        return true;
    }

    public function storesSemantic(): bool
    {
        return $this !== self::RAW;
    }

    public static function options(): array
    {
        return [
            self::RAW->value => 'raw',
            self::SEMANTIC->value => 'semantic',
            self::BOTH->value => 'both',
        ];
    }
}

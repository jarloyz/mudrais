<?php

namespace App\Application\Services;

final class CharacterStatusMapper
{
    private const KEY_UNIT = [
        'stress' => 'percent',
        'energy' => 'percent',
        'heart_rate' => 'bpm',
        'intimacy_player' => 'percent',
        'closeness_player' => 'percent',
        'emotion' => 'raw',
    ];

    /**
     * @param array<int, array<string, mixed>> $stateChanges
     * @param array<int, array<string, mixed>> $characterContext
     * @return array{rows: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public static function mapStateChangesToCharacterStatus(array $stateChanges = [], array $characterContext = [], string $source = 'system'): array
    {
        $characterIds = self::uniqueCharacterIds($characterContext);
        if ($characterIds === []) {
            return [
                'rows' => [],
                'warnings' => ['NO_CHARACTERS_IN_CONTEXT'],
            ];
        }

        $rows = [];
        $warnings = [];

        foreach ($stateChanges as $change) {
            $changeText = trim((string) ($change['change'] ?? ''));
            if ($changeText === '') {
                continue;
            }

            $targets = self::resolveTargetCharacterIds($change, $characterIds);
            $severity = max(1, min(5, (int) ($change['severity'] ?? 1)));
            $effects = self::extractExplicitKeyValue($changeText);
            if ($effects === []) {
                $effects = self::inferFromText($changeText, $severity);
            }

            if ($effects === []) {
                $warnings[] = 'UNMAPPED_STATE_CHANGE:'.mb_substr($changeText, 0, 80);
                continue;
            }

            foreach ($targets as $characterId) {
                foreach ($effects as $effect) {
                    $statusKey = self::normalizeStatusKey((string) $effect['key']);
                    $rows[] = [
                        'character_id' => $characterId,
                        'status_key' => $statusKey,
                        'status_value' => $statusKey === 'emotion' ? null : ($effect['value'] ?? null),
                        'status_text' => $statusKey === 'emotion' ? (($effect['text'] ?? null) ?: null) : null,
                        'unit' => self::KEY_UNIT[$statusKey] ?? null,
                        'source' => $source,
                    ];
                }
            }
        }

        return [
            'rows' => self::foldRows($rows),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $characterContext
     * @return array<int, string>
     */
    private static function uniqueCharacterIds(array $characterContext): array
    {
        $seen = [];
        $ids = [];

        foreach ($characterContext as $item) {
            $id = self::normalizeCharacterId((string) ($item['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $change
     * @param array<int, string> $allCharacterIds
     * @return array<int, string>
     */
    private static function resolveTargetCharacterIds(array $change, array $allCharacterIds): array
    {
        $scopeType = mb_strtolower(trim((string) ($change['scope_type'] ?? 'scene')));
        $scopeId = self::normalizeCharacterId((string) ($change['scope_id'] ?? ''));

        if ($scopeType === 'character' && $scopeId !== '') {
            return [$scopeId];
        }

        return $allCharacterIds;
    }

    /**
     * @return array<int, array{key: string, value: float|null, text: string|null}>
     */
    private static function extractExplicitKeyValue(string $changeText): array
    {
        preg_match_all('/\b(stress|energy|heart_rate|intimacy_player|closeness_player|emotion|emocion)\s*[:=]\s*([^\s,;]+)/iu', $changeText, $matches, PREG_SET_ORDER);
        $effects = [];

        foreach ($matches as $match) {
            $key = self::normalizeStatusKey($match[1] ?? '');
            $rawValue = trim((string) ($match[2] ?? ''));
            if ($key === '') {
                continue;
            }

            $effects[] = [
                'key' => $key,
                'value' => $key === 'emotion' ? null : (is_numeric(preg_replace('/[^\d.+-]/', '', $rawValue)) ? (float) preg_replace('/[^\d.+-]/', '', $rawValue) : null),
                'text' => $key === 'emotion' ? $rawValue : null,
            ];
        }

        return $effects;
    }

    /**
     * @return array<int, array{key: string, value: float|null, text: string|null}>
     */
    private static function inferFromText(string $changeText, int $severity): array
    {
        $text = mb_strtolower($changeText);
        $effects = [];

        if (preg_match('/ansied|estres|tens|panic|nerv/u', $text)) {
            $effects[] = ['key' => 'stress', 'value' => 6.0 * $severity, 'text' => null];
            $effects[] = ['key' => 'emotion', 'value' => null, 'text' => 'ansiosa'];
        }
        if (preg_match('/calm|relaj|tranquil|seren/u', $text)) {
            $effects[] = ['key' => 'stress', 'value' => -6.0 * $severity, 'text' => null];
            $effects[] = ['key' => 'emotion', 'value' => null, 'text' => 'calmada'];
        }
        if (preg_match('/cans|agot|fatig|debil/u', $text)) {
            $effects[] = ['key' => 'energy', 'value' => -7.0 * $severity, 'text' => null];
        }
        if (preg_match('/energ|vig|fuerte|animad/u', $text)) {
            $effects[] = ['key' => 'energy', 'value' => 5.0 * $severity, 'text' => null];
        }

        return $effects;
    }

    private static function normalizeStatusKey(string $key): string
    {
        $key = mb_strtolower(trim($key));

        return $key === 'emocion' ? 'emotion' : $key;
    }

    private static function normalizeCharacterId(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/', '_', $value) ?? $value;

        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function foldRows(array $rows): array
    {
        $folded = [];

        foreach ($rows as $row) {
            $key = $row['character_id'].'::'.$row['status_key'];
            if (! isset($folded[$key])) {
                $folded[$key] = $row;
                continue;
            }

            if ($row['status_value'] !== null) {
                $folded[$key]['status_value'] = (float) ($folded[$key]['status_value'] ?? 0) + (float) $row['status_value'];
            }
            if (($row['status_text'] ?? null) !== null) {
                $folded[$key]['status_text'] = $row['status_text'];
            }
            $folded[$key]['source'] = $row['source'] ?? $folded[$key]['source'];
        }

        return array_values($folded);
    }
}

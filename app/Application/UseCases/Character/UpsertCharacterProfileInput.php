<?php

declare(strict_types=1);

namespace App\Application\UseCases\Character;

use InvalidArgumentException;

final class UpsertCharacterProfileInput
{
    public readonly string $id;
    public readonly string $name;
    public readonly ?int $context_id;
    public readonly array $tags;
    public readonly array $traits;
    public readonly ?string $vault_id;

    public function __construct(
        string $id,
        string $name,
        ?int $context_id,
        array $tags,
        array $traits,
        ?string $vault_id = 'vault_default'
    ) {
        if (empty($id)) {
            throw new InvalidArgumentException("character.id es requerido");
        }
        $this->id = $id;
        $this->name = $name;
        $this->context_id = $context_id;
        $this->tags = $tags;
        $this->traits = $traits;
        $this->vault_id = $vault_id;
    }

    public static function fromArray(array $input): self
    {
        $base = $input;
        if (isset($base['characterJson']) && is_array($base['characterJson'])) {
            return self::normalizeFromCharacterJson($base['characterJson'], $base);
        }
        return self::normalizeDirectProfile($base);
    }

    private static function normalizeText(?string $value): string
    {
        return trim($value ?? "");
    }

    private static function normalizeIdentifier(?string $value, ?string $fallback = ""): string
    {
        $base = self::normalizeText($value) ?: self::normalizeText($fallback);
        if (empty($base)) {
            return "";
        }
        $base = strtolower($base);
        $base = iconv('UTF-8', 'ASCII//TRANSLIT', $base); // Remove diacritics
        $base = preg_replace('/[^a-z0-9_ -]+/', '', $base);
        $base = preg_replace('/[\s-]+/', '_', $base);
        $base = trim($base, '_');
        return $base;
    }

    private static function intOrNull(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $raw = trim($value);
        if ($raw === "") {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }
        $n = (int) $raw;
        return $n;
    }

    private static function normalizeTag($raw): string
    {
        if (is_array($raw) && (isset($raw['tag']) || isset($raw['name']))) {
            return self::normalizeIdentifier($raw['tag'] ?? $raw['name']);
        }
        return self::normalizeIdentifier($raw);
    }

    private static function normalizeTags(?array $items): array
    {
        $out = [];
        $seen = [];
        foreach ($items ?? [] as $raw) {
            $tag = self::normalizeTag($raw);
            if (empty($tag) || in_array($tag, $seen)) {
                continue;
            }
            $seen[] = $tag;
            $out[] = $tag;
        }
        return $out;
    }

    private static function sectionFromName(?string $name): ?string
    {
        return self::normalizeText($name) ?: null;
    }

    private static function pushSectionContent(array &$bucket, ?string $sectionName, $sectionData): void
    {
        $sort = 0;
        $section = self::sectionFromName($sectionName);
        $sec = is_array($sectionData) ? $sectionData : [];

        foreach ($sec['bullets'] ?? [] as $raw) {
            $text = self::normalizeText($raw);
            if (empty($text)) {
                continue;
            }
            $sort += 1;
            $bucket[] = ['section' => $section, 'text' => $text, 'sort_order' => $sort, 'parent_index' => null];
        }

        if (isset($sec['kv']) && is_array($sec['kv'])) {
            foreach ($sec['kv'] as $k => $v) {
                $key = self::normalizeText($k);
                if (empty($key)) {
                    continue;
                }
                if (is_array($v)) {
                    foreach ($v as $item) {
                        $value = self::normalizeText($item);
                        if (empty($value)) {
                            continue;
                        }
                        $sort += 1;
                        $bucket[] = ['section' => $section, 'text' => "{$key}: {$value}", 'sort_order' => $sort, 'parent_index' => null];
                    }
                } else {
                    $value = self::normalizeText($v);
                    if (empty($value)) {
                        continue;
                    }
                    $sort += 1;
                    $bucket[] = ['section' => $section, 'text' => "{$key}: {$value}", 'sort_order' => $sort, 'parent_index' => null];
                }
            }
        }

        foreach ($sec['paragraphs'] ?? [] as $raw) {
            $text = self::normalizeText($raw);
            if (empty($text)) {
                continue;
            }
            $sort += 1;
            $bucket[] = ['section' => $section, 'text' => $text, 'sort_order' => $sort, 'parent_index' => null];
        }
    }

    private static function normalizeTrait($raw, ?string $fallbackKey, int $sortOrder): ?array
    {
        $title = self::normalizeText($raw['title'] ?? null) ?: self::normalizeText($fallbackKey);
        $key = self::normalizeIdentifier($raw['key'] ?? null, $fallbackKey ?: $title);
        if (empty($key)) {
            return null;
        }

        $bullets = [];
        if (isset($raw['bullets']) && is_array($raw['bullets'])) {
            $idx = 0;
            foreach ($raw['bullets'] as $item) {
                $idx += 1;
                if (is_array($item)) {
                    $text = self::normalizeText($item['text'] ?? null);
                    if (empty($text)) {
                        continue;
                    }
                    $parentIndex = isset($item['parent_index']) && is_numeric($item['parent_index'])
                        ? (int) $item['parent_index']
                        : null;
                    $bullets[] = [
                        'section' => self::sectionFromName($item['section'] ?? null),
                        'text' => $text,
                        'sort_order' => isset($item['sort_order']) && is_numeric($item['sort_order'])
                            ? max(1, (int) $item['sort_order'])
                            : $idx,
                        'parent_index' => $parentIndex
                    ];
                    continue;
                }
                $text = self::normalizeText($item);
                if (empty($text)) {
                    continue;
                }
                $bullets[] = [
                    'section' => null,
                    'text' => $text,
                    'sort_order' => $idx,
                    'parent_index' => null
                ];
            }
        } elseif (isset($raw['sections']) && is_array($raw['sections'])) {
            foreach ($raw['sections'] as $sectionName => $sectionData) {
                self::pushSectionContent($bullets, $sectionName, $sectionData);
            }
        }

        return [
            'key' => $key,
            'title' => $title ?: $key,
            'sort_order' => isset($raw['sort_order']) && is_numeric($raw['sort_order'])
                ? max(0, (int) $raw['sort_order'])
                : max(0, $sortOrder),
            'bullets' => $bullets
        ];
    }

    private static function findModuleTags(array $modules): array
    {
        $base = $modules['base'] ?? [];
        $sections = $base['sections'] ?? [];
        foreach ($sections as $name => $section) {
            $normalized = self::normalizeIdentifier($name);
            if ($normalized !== 'tags') {
                continue;
            }
            return self::normalizeTags($section['bullets'] ?? []);
        }
        return [];
    }

    private static function normalizeFromCharacterJson(array $characterJson, array $overrides = []): self
    {
        $raw = $characterJson;
        $name = self::normalizeText($overrides['name'] ?? $raw['name'] ?? null);
        $id = self::normalizeIdentifier($overrides['id'] ?? $raw['id'] ?? null, $name);
        if (empty($id)) {
            throw new InvalidArgumentException("character.id es requerido");
        }

        $modules = $raw['modules'] ?? [];
        $moduleOrder = (isset($raw['modules_order']) && is_array($raw['modules_order']) && count($raw['modules_order']) > 0)
            ? $raw['modules_order']
            : array_keys($modules);

        $traits = [];
        $sort = 0;
        foreach ($moduleOrder as $moduleKeyRaw) {
            $moduleKey = self::normalizeText($moduleKeyRaw);
            if (empty($moduleKey)) {
                continue;
            }
            $moduleData = $modules[$moduleKey] ?? null;
            if (!is_array($moduleData)) {
                continue;
            }
            $sort += 1;
            $trait = self::normalizeTrait(
                [
                    'key' => $moduleKey,
                    'title' => $moduleData['title'] ?? null,
                    'sections' => $moduleData['sections'] ?? null
                ],
                $moduleKey,
                $sort
            );
            if (empty($trait)) {
                continue;
            }
            $traits[] = $trait;
        }

        return new self(
            $id,
            $name ?: $id,
            self::intOrNull($overrides['context_id'] ?? $overrides['contextId'] ?? $raw['context_id'] ?? $raw['contextId'] ?? null),
            self::normalizeTags($overrides['tags'] ?? self::findModuleTags($modules)),
            $traits,
            self::normalizeText($overrides['vault_id'] ?? $raw['vault_id'] ?? 'vault_default')
        );
    }

    private static function normalizeDirectProfile(array $input): self
    {
        $raw = $input;
        $name = self::normalizeText($raw['name'] ?? null);
        $id = self::normalizeIdentifier($raw['id'] ?? $raw['character_id'] ?? $raw['characterId'] ?? null, $name);
        if (empty($id)) {
            throw new InvalidArgumentException("character.id es requerido");
        }

        $traits = [];
        $sort = 0;
        foreach ($raw['traits'] ?? [] as $item) {
            $sort += 1;
            $trait = self::normalizeTrait($item, $item['key'] ?? $item['title'] ?? null, $sort);
            if (empty($trait)) {
                continue;
            }
            $traits[] = $trait;
        }

        return new self(
            $id,
            $name ?: $id,
            self::intOrNull($raw['context_id'] ?? $raw['contextId'] ?? null),
            self::normalizeTags($raw['tags'] ?? []),
            $traits,
            self::normalizeText($raw['vault_id'] ?? 'vault_default')
        );
    }
}

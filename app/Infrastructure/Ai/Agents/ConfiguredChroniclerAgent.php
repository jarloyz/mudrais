<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Agents\ChroniclerAgent;
use App\Application\Contracts\AiChatGateway;
use App\Domain\Scene\Activity;
use App\Infrastructure\Ai\Prompts\ChroniclerPrompt;
use App\Support\UserAiSettingsResolver;
use RuntimeException;
use Throwable;

final readonly class ConfiguredChroniclerAgent implements ChroniclerAgent
{
    public function __construct(
        private AiChatGateway $aiChatGateway,
        private UserAiSettingsResolver $settingsResolver,
    ) {
    }

    public function generate(Activity $scene, array $context, string $generatedMd, string $mode): array
    {
        $messages = ChroniclerPrompt::buildMessages($scene, $context, $generatedMd, $mode);
        $resolved = $this->settingsResolver->resolve();

        $response = $this->aiChatGateway->chat(
            model: $resolved['models']['librarian'],
            messages: $messages,
            temperature: 0.3,
            maxOutputTokens: 1500,
            timeoutMs: $resolved['timeout_ms'],
            cacheControl: null,
        );

        $text = trim((string) ($response['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('La IA no devolvió texto para el chronicler');
        }

        $clean = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $clean = preg_replace('/```$/', '', $clean) ?? $clean;
        $clean = trim($clean);

        try {
            $decoded = json_decode($clean, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RuntimeException('El chronicler no devolvio JSON valido: ' . $e->getMessage());
        }

        if (! is_array($decoded)) {
            $decoded = [];
        }

        return [
            'global_beats' => array_filter(array_map('trim', $decoded['global_beats'] ?? [])),
            'global_tags' => array_filter(array_map('trim', $decoded['global_tags'] ?? [])),
            'character_updates' => $this->normalizeCharacterUpdates($decoded['character_updates'] ?? []),
            'notes' => array_filter(array_map('trim', $decoded['notes'] ?? [])),
        ];
    }

    /**
     * @param mixed $updates
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCharacterUpdates(mixed $updates): array
    {
        if (! is_array($updates)) {
            return [];
        }

        $out = [];
        foreach ($updates as $update) {
            if (! is_array($update)) {
                continue;
            }
            $charId = trim((string) ($update['character_id'] ?? ''));
            if ($charId === '') {
                continue;
            }
            $out[] = [
                'character_id' => $charId,
                'beats' => array_filter(array_map('trim', $update['beats'] ?? [])),
                'tags' => array_filter(array_map('trim', $update['tags'] ?? [])),
            ];
        }
        return $out;
    }
}

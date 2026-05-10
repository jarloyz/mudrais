<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Domain\Scene\Activity;

final class SimpleScenePrompt
{
    /**
     * @param array<string, mixed> $context
     * @return array{stableContext:string,dynamicContext:string}
     */
    public static function buildSections(Activity $scene, array $context): array
    {
        $stable = [
            '## Escena simple',
            '- titulo: '.($scene->title !== '' ? $scene->title : '(sin titulo)'),
            '- objetivo: '.self::clip($scene->objective ?? '', 240, '(sin objetivo)'),
            '- restricciones generales: '.self::clip($scene->constraints ?? '', 260, '(sin restricciones)'),
            '## Jerarquia interpretativa',
            '- La voz y forma de hablar de cada personaje es la base principal de sus reacciones, dialogos y silencios.',
            '- Prioriza registro, ritmo, muletillas, subtexto y actitud del personaje antes que estilo narrativo generico.',
        ];

        if (! empty($context['location']['name'])) {
            $stable[] = '## Locacion';
            $stable[] = '- '.self::clip((string) $context['location']['name'], 180);
        }

        $characterLines = [];
        foreach (array_slice($context['characters'] ?? [], 0, 2) as $character) {
            $line = '- '.($character['name'] ?? $character['id'] ?? 'sin_nombre');
            if (! empty($character['voice'])) {
                $line .= ': '.self::clip((string) $character['voice'], 260);
            }
            $characterLines[] = $line;
        }

        if ($characterLines !== []) {
            $stable[] = '## Personajes cargados (voz base obligatoria)';
            array_push($stable, ...$characterLines);
        }

        $questLines = self::renderQuestLines($context['quests'] ?? []);
        if ($questLines !== []) {
            $stable[] = '## Quests activas y estado actual';
            array_push($stable, ...$questLines);
        }

        $dynamic = [
            '## Inicio de la escena',
            self::clip((string) ($context['sceneOpening'] ?? $scene->draft ?? ''), 1200, '(sin inicio disponible)'),
        ];

        if (! empty($context['historySummary'])) {
            $dynamic[] = '## Memoria resumida';
            $dynamic[] = self::clip((string) $context['historySummary'], 1200);
        }

        $recentLines = self::renderRecentMessages($context['recentMessages'] ?? []);
        if ($recentLines !== []) {
            $dynamic[] = '## Ultimos 4 mensajes del chat';
            array_push($dynamic, ...$recentLines);
        }

        $questDirectiveLines = self::renderQuestDirective($context['questDirective'] ?? null);
        if ($questDirectiveLines !== []) {
            $dynamic[] = '## Directiva de quest para este turno';
            array_push($dynamic, ...$questDirectiveLines);
        }

        return [
            'stableContext' => implode("\n", $stable),
            'dynamicContext' => implode("\n", $dynamic),
        ];
    }

    /**
     * @param mixed $messages
     * @return array<int, string>
     */
    public static function renderRecentMessages(mixed $messages): array
    {
        if (! is_array($messages)) {
            return [];
        }

        $lines = [];

        foreach (array_slice($messages, -4) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $role = ($item['role'] ?? 'user') === 'assistant' ? 'asistente' : 'usuario';
            $content = self::clip((string) ($item['content'] ?? ''), 260);
            if ($content !== '') {
                $lines[] = "- {$role}: {$content}";
            }
        }

        return $lines;
    }

    /**
     * @param mixed $quests
     * @return array<int, string>
     */
    public static function renderQuestLines(mixed $quests): array
    {
        if (! is_array($quests)) {
            return [];
        }

        $lines = [];

        foreach (array_slice($quests, 0, 6) as $quest) {
            if (! is_array($quest)) {
                continue;
            }

            $title = trim((string) ($quest['title'] ?? $quest['quest_id'] ?? ''));
            if ($title === '') {
                continue;
            }

            $status = trim((string) ($quest['status'] ?? 'active'));
            $stage = (int) ($quest['current_stage_number'] ?? 0);
            $step = is_array($quest['current_step'] ?? null) ? trim((string) (($quest['current_step']['description'] ?? ''))) : '';
            $summary = trim((string) ($quest['ai_summary'] ?? ''));

            $line = "- {$title} | estado: {$status} | etapa: {$stage}";
            if ($step !== '') {
                $line .= ' | objetivo actual: '.self::clip($step, 180);
            }
            if ($summary !== '') {
                $line .= ' | nota: '.self::clip($summary, 180);
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    public static function renderQuestDirective(mixed $directive): array
    {
        if (! is_array($directive) || ! (bool) ($directive['matched'] ?? false)) {
            return [];
        }

        $lines = [];
        if (! empty($directive['quest_id'])) {
            $lines[] = '- quest_id: '.trim((string) $directive['quest_id']);
        }
        if (! empty($directive['directive_for_writer'])) {
            $lines[] = '- instruccion obligatoria: '.self::clip((string) $directive['directive_for_writer'], 260);
        }
        if (array_key_exists('new_stage_number', $directive) && $directive['new_stage_number'] !== null) {
            $lines[] = '- nueva_etapa: '.(int) $directive['new_stage_number'];
        }
        if (! empty($directive['new_status'])) {
            $lines[] = '- nuevo_estado: '.trim((string) $directive['new_status']);
        }

        return $lines;
    }

    private static function clip(string $value, int $maxChars, string $fallback = ''): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }

        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $maxChars - 3))).'...';
    }
}

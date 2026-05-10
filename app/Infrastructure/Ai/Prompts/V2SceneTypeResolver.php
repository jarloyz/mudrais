<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Domain\Scene\Activity;

final class V2SceneTypeResolver
{
    /**
     * @param array<string, mixed> $context
     * @return array{sceneType:string,reasons:array<int,string>}
     */
    public static function resolveSceneType(Activity $scene, array $context): array
    {
        $explicit = self::extractDirective($scene);
        if ($explicit !== null) {
            return ['sceneType' => $explicit, 'reasons' => ["scene_type:{$explicit}"]];
        }

        $reasons = [];
        if (! empty($context['events'])) {
            $reasons[] = 'events';
        }
        if (! empty($context['stateChanges'])) {
            $reasons[] = 'state_changes';
        }
        if (collect($context['characters'] ?? [])->contains(fn ($character): bool => ! empty($character['profile']['triggers']) || ! empty($character['profile']['runtimeStatus']))) {
            $reasons[] = 'character_triggers_or_runtime';
        }

        $sceneText = mb_strtolower(trim(($scene->objective ?? '')."\n".($scene->constraints ?? '')."\n".($scene->draft ?? '')));
        if (preg_match('/\b(disparador|trigger|cooldown|evento|rama|continuidad)\b/u', $sceneText)) {
            $reasons[] = 'scene_text_hints';
        }

        return [
            'sceneType' => count($reasons) > 0 ? 'complex' : 'simple',
            'reasons' => $reasons,
        ];
    }

    private static function extractDirective(Activity $scene): ?string
    {
        foreach ([$scene->objective, $scene->constraints, $scene->draft] as $source) {
            if (! is_string($source) || trim($source) === '') {
                continue;
            }

            if (preg_match('/(?:^|\R)\s*(?:tipo_escena|scene_type)\s*:\s*([^\r\n]+)/iu', $source, $matches) === 1) {
                $raw = mb_strtolower(trim($matches[1]));
                if (in_array($raw, ['simple', 'sencilla', 'basica', 'básica', 'basic'], true)) {
                    return 'simple';
                }
                if (in_array($raw, ['complex', 'complexa', 'compleja', 'triggered', 'con_triggers', 'con-trigger'], true)) {
                    return 'complex';
                }
            }
        }

        return null;
    }
}

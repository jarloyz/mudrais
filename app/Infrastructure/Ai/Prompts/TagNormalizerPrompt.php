<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Models\AiPromptTemplate;

class TagNormalizerPrompt
{
    /**
     * @param array<int, array{slug:string,name:string,score:float}> $candidates
     */
    public static function getVerifyPrompt(string $rawInput, array $candidates): string
    {
        $list = implode("\n", array_map(
            fn ($c) => "- {$c['slug']}: {$c['name']} (similitud {$c['score']})",
            $candidates,
        ));

        return str_replace(
            ['{raw_input}', '{candidates_list}'],
            [$rawInput, $list],
            AiPromptTemplate::getBodyOrFail('tag_normalizer_verify'),
        );
    }

    public static function getCreatePrompt(string $rawInput): string
    {
        return str_replace(
            '{raw_input}',
            $rawInput,
            AiPromptTemplate::getBodyOrFail('tag_normalizer_create'),
        );
    }
}

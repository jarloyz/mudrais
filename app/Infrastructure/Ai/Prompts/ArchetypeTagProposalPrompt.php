<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Models\AiPromptTemplate;

class ArchetypeTagProposalPrompt
{
    public static function getPrompt(string $optimizedText, array $existingSlugs): string
    {
        $excludedSlugs = empty($existingSlugs) ? 'none' : implode(', ', $existingSlugs);

        return str_replace(
            ['{existing_slugs}', '{optimized_text}'],
            [$excludedSlugs, $optimizedText],
            AiPromptTemplate::getBodyOrFail('archetype_tag_proposal'),
        );
    }
}

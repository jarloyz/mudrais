<?php

namespace App\Infrastructure\Ai\Prompts;

use App\Models\AiPromptTemplate;

class GatekeeperPrompt
{
    /**
     * @param array<int, string> $playerTags
     */
    public static function buildInstruction(array $playerTags, string $vaultSynopsis, string $locationName, string $locationDesc, string $playerConcept = 'Ciudadano común'): string
    {
        $tagsString = empty($playerTags)
            ? 'Ninguna (El personaje está en condiciones normales).'
            : json_encode($playerTags, JSON_UNESCAPED_UNICODE);

        return str_replace(
            ['{vault_synopsis}', '{location_name}', '{location_desc}', '{player_concept}', '{player_tags}'],
            [$vaultSynopsis, $locationName, $locationDesc, $playerConcept, $tagsString],
            AiPromptTemplate::getBodyOrFail('gatekeeper'),
        );
    }
}

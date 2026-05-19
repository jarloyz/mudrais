<?php

namespace App\Infrastructure\Ai\Agents;

use Illuminate\Support\Facades\Log;

/**
 * Variante de voz del RegistrationAnalystAgent.
 *
 * Diferencia clave: acepta valores con ≥ 1 carácter (vs ≥ 3 del original),
 * lo que permite campos boolean ("yes"/"no") y selects de una sola palabra.
 */
class VoiceAnalystAgent
{
    private const MIN_VALUE_LENGTH = 1;

    /**
     * @param array<string,string> $currentExtracted
     * @param list<string>         $requiredFieldKeys
     * @param list<string>         $optionalFieldKeys
     * @return array{is_complete:bool, missing_required:list<string>, missing_optional:list<string>, complete_fields:array<string,string>}
     */
    public function analyze(
        array $currentExtracted,
        array $requiredFieldKeys,
        array $optionalFieldKeys = [],
    ): array {
        Log::debug('[VoiceAnalystAgent@analyze] Inicio', [
            'extracted_count' => count($currentExtracted),
            'required_count'  => count($requiredFieldKeys),
            'optional_count'  => count($optionalFieldKeys),
        ]);

        $completeFields  = [];
        $missingRequired = [];
        $missingOptional = [];

        foreach ($currentExtracted as $key => $value) {
            if (is_string($value) && mb_strlen(trim($value)) >= self::MIN_VALUE_LENGTH) {
                $completeFields[$key] = $value;
            }
        }

        foreach ($requiredFieldKeys as $key) {
            if (! isset($completeFields[$key])) {
                $missingRequired[] = $key;
            }
        }

        foreach ($optionalFieldKeys as $key) {
            if (! isset($completeFields[$key])) {
                $missingOptional[] = $key;
            }
        }

        $isComplete = empty($missingRequired);

        Log::debug('[VoiceAnalystAgent@analyze] Resultado', [
            'is_complete'      => $isComplete,
            'missing_required' => $missingRequired,
            'missing_optional' => $missingOptional,
        ]);

        return [
            'is_complete'      => $isComplete,
            'missing_required' => array_values($missingRequired),
            'missing_optional' => array_values($missingOptional),
            'complete_fields'  => $completeFields,
        ];
    }
}

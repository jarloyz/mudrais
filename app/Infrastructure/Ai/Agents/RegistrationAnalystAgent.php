<?php

namespace App\Infrastructure\Ai\Agents;

use Illuminate\Support\Facades\Log;

/**
 * Agente puro PHP — sin LLM.
 * Determina si el registro está completo comparando los campos extraídos
 * contra los field_keys obligatorios. Un campo se considera completo
 * cuando su valor tiene al menos 3 caracteres tras recortar espacios.
 */
class RegistrationAnalystAgent
{
    private const MIN_VALUE_LENGTH = 3;

    /**
     * Analiza el estado de completitud del registro.
     *
     * La completitud se determina solo por los campos required.
     * Los opcionales se reportan aparte para que el entrevistador pueda preguntarlos
     * si todavía quedan turnos disponibles.
     *
     * @param array<string,string> $currentExtracted    Todos los campos extraídos hasta ahora
     * @param list<string>         $requiredFieldKeys   Field keys obligatorios
     * @param list<string>         $optionalFieldKeys   Field keys opcionales
     * @return array{is_complete:bool, missing_required:list<string>, missing_optional:list<string>, complete_fields:array<string,string>}
     */
    public function analyze(array $currentExtracted, array $requiredFieldKeys, array $optionalFieldKeys = []): array
    {
        Log::debug('[RegistrationAnalystAgent@analyze] Inicio', [
            'extracted_count' => count($currentExtracted),
            'required_count'  => count($requiredFieldKeys),
            'optional_count'  => count($optionalFieldKeys),
        ]);

        $missingRequired = [];
        $missingOptional = [];
        $completeFields  = [];

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

        Log::debug('[RegistrationAnalystAgent@analyze] Resultado', [
            'is_complete'      => $isComplete,
            'missing_required' => $missingRequired,
            'missing_optional' => $missingOptional,
            'complete_count'   => count($completeFields),
        ]);

        return [
            'is_complete'      => $isComplete,
            'missing_required' => array_values($missingRequired),
            'missing_optional' => array_values($missingOptional),
            'complete_fields'  => $completeFields,
        ];
    }
}

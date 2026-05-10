<?php
$file = 'app/Domains/Matchmaking/Services/ArchetypeMutatorService.php';
$content = file_get_contents($file);

$newMethod = <<<PHP
    /**
     * Construye el modal completo de step2.
     * Usa los mutators de context='registration' que no entraron en step1.
     * Si no hay suficientes, rellena con los campos por defecto.
     */
    public function buildStep2Modal(?int \$archetypeId, array \$prefill = []): array
    {
        Log::debug('[ArchetypeMutatorService@buildStep2Modal]', [
            'archetype_id' => \$archetypeId,
        ]);

        \$availableStep1 = self::DISCORD_MODAL_MAX - self::BASE_STEP1_SLOTS;
        \$mutatorRows = \$archetypeId
            ? \$this->buildDiscordComponents(\$archetypeId, 'registration', \$prefill)
            : [];

        // Tomar los mutators que no se usaron en step1
        \$mutatorRows = array_slice(\$mutatorRows, \$availableStep1);

        // Limitar a máximo 5
        \$mutatorRows = array_slice(\$mutatorRows, 0, self::DISCORD_MODAL_MAX);

        if (empty(\$mutatorRows)) {
            \$mutatorRows = \$this->defaultStep2Rows(\$prefill);
        }

        return [
            'custom_id'  => 'mudrais_registro_step_2',
            'title'      => 'Registro MUDRAIS (2/2)',
            'components' => \$mutatorRows,
        ];
    }

    /**
     * Campos por defecto para el step 2.
     */
    private function defaultStep2Rows(array \$prefill): array
    {
        return [
            [
                'type'       => 1,
                'components' => [array_filter([
                    'type'        => 4,
                    'custom_id'   => 'lineas_rojas',
                    'label'       => 'Límites Absolutos (Rojas)',
                    'style'       => 2,
                    'placeholder' => 'Temas prohibidos para ti. Nunca verás partidas con esto.',
                    'required'    => false,
                    'value'       => \$prefill['lineas_rojas'] ?? null,
                ], fn (\$v) => \$v !== null)],
            ],
            [
                'type'       => 1,
                'components' => [array_filter([
                    'type'        => 4,
                    'custom_id'   => 'lineas_amarillas',
                    'label'       => 'Preferencias a Evitar (Amarillas)',
                    'style'       => 2,
                    'placeholder' => 'Máximo 10, ordenados de más a menos desagradable.',
                    'required'    => false,
                    'value'       => \$prefill['lineas_amarillas'] ?? null,
                ], fn (\$v) => \$v !== null)],
            ],
            [
                'type'       => 1,
                'components' => [array_filter([
                    'type'        => 4,
                    'custom_id'   => 'favoritos',
                    'label'       => 'Tus Favoritos',
                    'style'       => 2,
                    'placeholder' => 'Géneros, tropos o temas. Máximo 10.',
                    'required'    => true,
                    'value'       => \$prefill['favoritos'] ?? null,
                ], fn (\$v) => \$v !== null)],
            ],
            [
                'type'       => 1,
                'components' => [array_filter([
                    'type'        => 4,
                    'custom_id'   => 'vibra',
                    'label'       => 'Resumen de tu Estilo',
                    'style'       => 2,
                    'placeholder' => 'Sé directo. Ej: 3ra persona, drama psicológico, desarrollo lento...',
                    'required'    => true,
                    'max_length'  => 300,
                    'value'       => \$prefill['vibra'] ?? null,
                ], fn (\$v) => \$v !== null)],
            ],
            [
                'type'       => 1,
                'components' => [array_filter([
                    'type'        => 4,
                    'custom_id'   => 'input_biografia_pg',
                    'label'       => 'Carta de Presentación (Comunidad)',
                    'style'       => 2,
                    'placeholder' => '¡Exprésate! Pon emojis, tu historia, links...',
                    'required'    => false,
                    'max_length'  => 1500,
                    'value'       => \$prefill['input_biografia_pg'] ?? null,
                ], fn (\$v) => \$v !== null)],
            ],
        ];
    }

    /**
     * Construye los componentes para los pasos de creación de Vault.
PHP;

$content = str_replace("    /**\n     * Construye los componentes para los pasos de creación de Vault.", $newMethod, $content);
file_put_contents($file, $content);
echo "ArchetypeMutatorService patched.\\n";

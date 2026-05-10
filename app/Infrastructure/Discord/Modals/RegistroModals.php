<?php

namespace App\Infrastructure\Discord\Modals;

class RegistroModals
{
    /**
     * Modal paso 1: datos básicos del jugador.
     * Solo se pide la primera vez.
     *
     * @param bool  $error   Muestra título de error en el título del modal.
     * @param array $prefill Valores pre-rellenos por campo (claves: nombre, edad, nacionalidad, genero, about_me).
     */
    public static function step1(bool $error = false, array $prefill = []): array
    {
        return [
            'custom_id'  => 'mudrais_registro_step_1',
            'title'      => $error ? '⚠️ Datos Básicos — Revisa los datos' : 'Registro MUDRAIS (Datos Básicos)',
            'components' => [
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'nombre',
                        'label'       => 'Nombre / Apodo',
                        'style'       => 1,
                        'placeholder' => 'Ej: Alex',
                        'required'    => true,
                        'max_length'  => 50,
                        'value'       => $prefill['nombre'] ?? null,
                    ], fn ($v) => $v !== null)],
                ],
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'edad',
                        'label'       => 'Edad',
                        'style'       => 1,
                        'placeholder' => 'Ej: 28',
                        'required'    => true,
                        'max_length'  => 3,
                        'value'       => $prefill['edad'] ?? null,
                    ], fn ($v) => $v !== null)],
                ],
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'nacionalidad',
                        'label'       => 'Nacionalidad',
                        'style'       => 1,
                        'placeholder' => 'Ej: México',
                        'required'    => true,
                        'max_length'  => 50,
                        'value'       => $prefill['nacionalidad'] ?? null,
                    ], fn ($v) => $v !== null)],
                ],
                [
                    'type'       => 1,
                    'components' => [[
                        'type'        => 3,
                        'custom_id'   => 'genero',
                        'placeholder' => 'Género: Hombre / Mujer / No binario / Otro',
                        'required'    => true,
                        'options'     => array_map(
                            function (string $opt) use ($prefill): array {
                                $option = ['label' => $opt, 'value' => $opt];
                                if (($prefill['genero'] ?? null) === $opt) {
                                    $option['default'] = true;
                                }
                                return $option;
                            },
                            ['Hombre', 'Mujer', 'No binario', 'Otro']
                        ),
                    ]],
                ],
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'about_me',
                        'label'       => 'Carta de Presentación (Comunidad)',
                        'style'       => 2,
                        'placeholder' => '¡Exprésate! Pon emojis, tu historia, links...',
                        'required'    => false,
                        'max_length'  => 1500,
                        'value'       => $prefill['about_me'] ?? null,
                    ], fn ($v) => $v !== null)],
                ],
            ],
        ];
    }

    /**
     * Modal paso 2 (Fallback): preferencias y estilo de rol si no hay mutadores.
     *
     * @param array $prefill Valores pre-rellenos (claves: red_lines, yellow_lines, preferences, style).
     */
    public static function step2(array $prefill = []): array
    {
        return [
            'custom_id'  => 'mudrais_registro_step_2',
            'title'      => 'Ficha de Arquetipo',
            'components' => [
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'red_lines',
                        'label'       => 'Absolute Limits (Red)',
                        'style'       => 2,
                        'placeholder' => 'Topics forbidden for you. You will never see games with these.',
                        'required'    => false,
                        'value'       => $prefill['red_lines'] ?? null,
                    ], fn ($v) => $v !== null)],
                ],
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'yellow_lines',
                        'label'       => 'Topics to Avoid (Yellow)',
                        'style'       => 2,
                        'placeholder' => 'Max 10, ordered from most to least unpleasant.',
                        'required'    => false,
                        'value'       => $prefill['yellow_lines'] ?? null,
                    ], fn ($v) => $v !== null)],
                ],
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'preferences',
                        'label'       => 'Your Favorites',
                        'style'       => 2,
                        'placeholder' => 'Genres, tropes or themes. Max 10, ordered by preference.',
                        'required'    => true,
                        'value'       => $prefill['preferences'] ?? null,
                    ], fn ($v) => $v !== null)],
                ],
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'style',
                        'label'       => 'Your Style Summary',
                        'style'       => 2,
                        'placeholder' => 'Be direct. E.g. 3rd person, psychological drama, slow burn...',
                        'required'    => true,
                        'max_length'  => 300,
                        'value'       => $prefill['style'] ?? null,
                    ], fn ($v) => $v !== null)],
                ],
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'schedule_raw',
                        'label'       => 'Availability / Schedule',
                        'style'       => 1,
                        'placeholder' => 'E.g. weekends, evenings UTC-5, ~3h/week',
                        'required'    => false,
                        'max_length'  => 200,
                        'value'       => $prefill['schedule_raw'] ?? null,
                    ], fn ($v) => $v !== null)],
                ],
            ],
        ];
    }
}

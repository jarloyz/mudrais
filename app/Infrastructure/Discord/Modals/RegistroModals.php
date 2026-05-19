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
        // Los valores de género se almacenan en español en DB; las etiquetas se muestran traducidas.
        $genderValues = ['Hombre', 'Mujer', 'No binario', 'Otro'];
        $genderLabels = [
            'Hombre'     => __('discord.modal_step1_gender_male'),
            'Mujer'      => __('discord.modal_step1_gender_female'),
            'No binario' => __('discord.modal_step1_gender_nonbinary'),
            'Otro'       => __('discord.modal_step1_gender_other'),
        ];

        return [
            'custom_id'  => 'mudrais_registro_step_1',
            'title'      => $error
                ? __('discord.modal_step1_title_error')
                : __('discord.modal_step1_title'),
            'components' => [
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'nombre',
                        'label'       => __('discord.modal_step1_label_name'),
                        'style'       => 1,
                        'placeholder' => __('discord.modal_step1_placeholder_name'),
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
                        'label'       => __('discord.modal_step1_label_age'),
                        'style'       => 1,
                        'placeholder' => __('discord.modal_step1_placeholder_age'),
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
                        'label'       => __('discord.modal_step1_label_nationality'),
                        'style'       => 1,
                        'placeholder' => __('discord.modal_step1_placeholder_nat'),
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
                        'placeholder' => __('discord.modal_step1_placeholder_gender'),
                        'required'    => true,
                        'options'     => array_map(
                            function (string $value) use ($prefill, $genderLabels): array {
                                $option = ['label' => $genderLabels[$value], 'value' => $value];
                                if (($prefill['genero'] ?? null) === $value) {
                                    $option['default'] = true;
                                }
                                return $option;
                            },
                            $genderValues
                        ),
                    ]],
                ],
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'about_me',
                        'label'       => __('discord.modal_step1_label_about'),
                        'style'       => 2,
                        'placeholder' => __('discord.modal_step1_placeholder_about'),
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
            'title'      => __('discord.modal_step2_title'),
            'components' => [
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'red_lines',
                        'label'       => __('discord.modal_step2_label_red'),
                        'style'       => 2,
                        'placeholder' => __('discord.modal_step2_placeholder_red'),
                        'required'    => false,
                        'value'       => $prefill['red_lines'] ?? null,
                    ], fn ($v) => $v !== null)],
                ],
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'yellow_lines',
                        'label'       => __('discord.modal_step2_label_yellow'),
                        'style'       => 2,
                        'placeholder' => __('discord.modal_step2_placeholder_yellow'),
                        'required'    => false,
                        'value'       => $prefill['yellow_lines'] ?? null,
                    ], fn ($v) => $v !== null)],
                ],
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'preferences',
                        'label'       => __('discord.modal_step2_label_prefs'),
                        'style'       => 2,
                        'placeholder' => __('discord.modal_step2_placeholder_prefs'),
                        'required'    => true,
                        'value'       => $prefill['preferences'] ?? null,
                    ], fn ($v) => $v !== null)],
                ],
                [
                    'type'       => 1,
                    'components' => [array_filter([
                        'type'        => 4,
                        'custom_id'   => 'style',
                        'label'       => __('discord.modal_step2_label_style'),
                        'style'       => 2,
                        'placeholder' => __('discord.modal_step2_placeholder_style'),
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
                        'label'       => __('discord.modal_step2_label_schedule'),
                        'style'       => 1,
                        'placeholder' => __('discord.modal_step2_placeholder_schedule'),
                        'required'    => false,
                        'max_length'  => 200,
                        'value'       => $prefill['schedule_raw'] ?? null,
                    ], fn ($v) => $v !== null)],
                ],
            ],
        ];
    }
}

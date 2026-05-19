<?php

namespace App\Infrastructure\Discord\Embeds;

/**
 * Construye los payloads de embed para el flujo /registro.
 *
 * Todos los métodos devuelven el array completo de respuesta Discord (type + data)
 * listo para pasar directamente a response()->json().
 */
class RegistroEmbeds
{
    private const COLOR_GREEN  = 5763719;  // #57F287
    private const COLOR_BLUE   = 3447003;  // #3498DB
    private const COLOR_GOLD   = 16766720; // #FFD700
    private const COLOR_RED    = 15548997; // #ED4245

    /**
     * Embed de bienvenida para jugadores nuevos.
     * 3 botones de género/pronombres — el clic cachea la selección y abre el Modal Step 1.
     */
    public static function introNuevo(): array
    {
        return [
            'type' => 4,
            'data' => [
                'flags'  => 64,
                'embeds' => [[
                    'title'       => __('discord.registro_intro_nuevo_title'),
                    'description' => __('discord.registro_intro_nuevo_desc'),
                    'color'       => self::COLOR_GREEN,
                    'footer'      => ['text' => __('discord.footer')],
                ]],
                'components' => [[
                    'type'       => 1,
                    'components' => [
                        [
                            'type'      => 2,
                            'style'     => 1,
                            'label'     => __('discord.registro_btn_male'),
                            'custom_id' => 'btn_reg_hombre',
                        ],
                        [
                            'type'      => 2,
                            'style'     => 1,
                            'label'     => __('discord.registro_btn_female'),
                            'custom_id' => 'btn_reg_mujer',
                        ],
                        [
                            'type'      => 2,
                            'style'     => 2,
                            'label'     => __('discord.registro_btn_other'),
                            'custom_id' => 'btn_reg_otro',
                        ],
                    ],
                ]],
            ],
        ];
    }

    /**
     * Embed para jugadores existentes que quieren editar su ficha.
     * Muestra botones separados para editar Datos Básicos o la Ficha de Arquetipo.
     */
    public static function introEdicion(int $coinBalance, int $cost, bool $withBeta = false, bool $withGamma = false): array
    {
        $interviewButton = $withBeta
            ? ['type' => 2, 'style' => 3, 'label' => __('discord.interview_beta_btn_label'), 'custom_id' => 'btn_iniciar_entrevista_beta']
            : ['type' => 2, 'style' => 2, 'label' => __('discord.interview_btn_label'),      'custom_id' => 'btn_interview_start'];

        $buttons = [
            [
                'type'      => 2,
                'style'     => 2,
                'label'     => __('discord.registro_edicion_btn_basics'),
                'custom_id' => 'btn_abrir_modal_1_edicion',
            ],
            [
                'type'      => 2,
                'style'     => 1,
                'label'     => __('discord.registro_edicion_btn_archetype'),
                'custom_id' => 'btn_abrir_modal_2',
            ],
            $interviewButton,
        ];

        if ($withGamma) {
            $buttons[] = [
                'type'      => 2,
                'style'     => 2,
                'label'     => __('discord.voice_interview_btn_label'),
                'custom_id' => 'btn_voice_interview_start',
                'emoji'     => ['name' => '🎙️'],
            ];
        }

        return [
            'type' => 4,
            'data' => [
                'flags'  => 64,
                'embeds' => [[
                    'title'       => __('discord.registro_edicion_title'),
                    'description' => __('discord.registro_edicion_desc', ['cost' => $cost, 'balance' => $coinBalance]),
                    'color'       => self::COLOR_BLUE,
                    'footer'      => ['text' => __('discord.footer')],
                ]],
                'components' => [['type' => 1, 'components' => $buttons]],
            ],
        ];
    }

    /**
     * Embed para jugadores con datos básicos guardados pero sin perfil de arquetipo en el vault actual.
     * Salta Step 1 e invoca directamente btn_abrir_modal_2 (creación gratuita).
     */
    public static function introCompletarArquetipo(bool $withBeta = false, bool $withGamma = false): array
    {
        $interviewButton = $withBeta
            ? ['type' => 2, 'style' => 3, 'label' => __('discord.interview_beta_btn_label'), 'custom_id' => 'btn_iniciar_entrevista_beta']
            : ['type' => 2, 'style' => 2, 'label' => __('discord.interview_btn_label'),      'custom_id' => 'btn_interview_start'];

        $buttons = [
            [
                'type'      => 2,
                'style'     => 1,
                'label'     => __('discord.registro_completar_btn'),
                'custom_id' => 'btn_abrir_modal_2',
            ],
            $interviewButton,
        ];

        if ($withGamma) {
            $buttons[] = [
                'type'      => 2,
                'style'     => 2,
                'label'     => __('discord.voice_interview_btn_label'),
                'custom_id' => 'btn_voice_interview_start',
                'emoji'     => ['name' => '🎙️'],
            ];
        }

        return [
            'type' => 4,
            'data' => [
                'flags'  => 64,
                'embeds' => [[
                    'title'       => __('discord.registro_completar_title'),
                    'description' => __('discord.registro_completar_desc'),
                    'color'       => self::COLOR_GREEN,
                    'footer'      => ['text' => __('discord.footer')],
                ]],
                'components' => [['type' => 1, 'components' => $buttons]],
            ],
        ];
    }

    /**
     * Mensaje puente para flujos paginados del Paso 2.
     */
    public static function puenteStep2Paginado(int $nextPage, int $totalPages): array
    {
        return [
            'content' => '',
            'embeds'  => [[
                'title'       => __('discord.registro_puente_paginado_title', ['current' => $nextPage, 'total' => $totalPages]),
                'description' => __('discord.registro_puente_paginado_desc'),
                'color'       => self::COLOR_BLUE,
                'footer'      => ['text' => __('discord.footer')],
            ]],
            'components' => [[
                'type'       => 1,
                'components' => [[
                    'type'      => 2,
                    'style'     => 1,
                    'label'     => __('discord.registro_puente_paginado_btn', ['next' => $nextPage + 1]),
                    'custom_id' => "btn_abrir_modal_2:{$nextPage}",
                ]],
            ]],
        ];
    }

    /**
     * Mensaje puente que reemplaza el embed de bienvenida tras un Step 1 exitoso.
     * Aparece mientras el usuario no ha abierto el Modal 2 todavía.
     */
    public static function puenteStep2(): array
    {
        return [
            'content' => '',
            'embeds'  => [[
                'title'       => __('discord.registro_puente_step2_title'),
                'description' => __('discord.registro_puente_step2_desc'),
                'color'       => self::COLOR_BLUE,
                'footer'      => ['text' => __('discord.footer')],
            ]],
            'components' => [[
                'type'       => 1,
                'components' => [[
                    'type'      => 2,
                    'style'     => 1,
                    'label'     => __('discord.registro_puente_step2_btn'),
                    'custom_id' => 'btn_abrir_modal_2',
                ]],
            ]],
        ];
    }

    /**
     * Embed de error Step 1 con botón retry — reemplaza el mensaje original.
     * (Versión type:7, solo data sin type/flags.)
     */
    public static function errorStep1Data(string $errorMessage): array
    {
        return [
            'content' => '',
            'embeds'  => [[
                'title'       => __('discord.registro_error_step1_title'),
                'description' => __('discord.registro_error_step1_desc', ['error' => $errorMessage]),
                'color'       => self::COLOR_RED,
            ]],
            'components' => [[
                'type'       => 1,
                'components' => [[
                    'type'      => 2,
                    'style'     => 4,
                    'label'     => __('discord.registro_error_step1_btn'),
                    'custom_id' => 'btn_retry_modal_1',
                ]],
            ]],
        ];
    }

    /**
     * Embed de error Step 2 con botón retry — reemplaza el mensaje puente.
     */
    public static function errorStep2Data(string $errorMessage, int $page = 0): array
    {
        return [
            'content' => '',
            'embeds'  => [[
                'title'       => __('discord.registro_error_step2_title'),
                'description' => __('discord.registro_error_step2_desc', ['error' => $errorMessage]),
                'color'       => self::COLOR_RED,
            ]],
            'components' => [[
                'type'       => 1,
                'components' => [[
                    'type'      => 2,
                    'style'     => 4,
                    'label'     => __('discord.registro_error_step2_btn'),
                    'custom_id' => "btn_retry_modal_2:{$page}",
                ]],
            ]],
        ];
    }

    /**
     * Data del embed de éxito para registro nuevo (para usar con type:7).
     */
    public static function exitoRegistro(string $username): array
    {
        return [
            'content'    => '',
            'embeds'     => [[
                'title'       => __('discord.registro_exito_nuevo_title'),
                'description' => __('discord.registro_exito_nuevo_desc', ['username' => $username]),
                'color'       => self::COLOR_GREEN,
                'footer'      => ['text' => __('discord.footer')],
            ]],
            'components' => [],
        ];
    }

    /**
     * Data del embed de éxito para edición de perfil (para usar con type:7).
     */
    public static function exitoEdicion(string $username, int $coinsRemaining): array
    {
        return [
            'content'    => '',
            'embeds'     => [[
                'title'       => __('discord.registro_exito_edit_title'),
                'description' => __('discord.registro_exito_edit_desc', ['username' => $username, 'coins' => $coinsRemaining]),
                'color'       => self::COLOR_GOLD,
                'footer'      => ['text' => __('discord.footer')],
            ]],
            'components' => [],
        ];
    }

    /**
     * Respuesta efímera de error con botón de reintento para Modal Step 1.
     */
    public static function errorStep1(string $errorMessage): array
    {
        return [
            'type' => 4,
            'data' => [
                'flags'  => 64,
                'embeds' => [[
                    'title'       => __('discord.registro_error_step1_title'),
                    'description' => __('discord.registro_error_step1_desc', ['error' => $errorMessage]),
                    'color'       => self::COLOR_RED,
                ]],
                'components' => [[
                    'type'       => 1,
                    'components' => [[
                        'type'      => 2,
                        'style'     => 4,
                        'label'     => __('discord.registro_error_step1_btn'),
                        'custom_id' => 'btn_retry_modal_1',
                    ]],
                ]],
            ],
        ];
    }
}

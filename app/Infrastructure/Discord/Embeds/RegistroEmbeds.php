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
                    'title'       => '¡Bienvenido a MUDRAIS!',
                    'description' => implode("\n", [
                        'Conecta con miles de roleros afines a tu estilo.',
                        '',
                        'Al registrarte aceptas nuestros **términos de comunidad**: respeto, ',
                        'no spam y contenido apropiado para el servidor.',
                        '',
                        '**El registro es gratuito y solo tarda 2 pasos.**',
                        '',
                        'Para comenzar, **selecciona tu sexo/pronombres:**',
                    ]),
                    'color' => self::COLOR_GREEN,
                    'footer' => ['text' => 'MUDRAIS · Sistema de Emparejamiento de Rol'],
                ]],
                'components' => [[
                    'type'       => 1,
                    'components' => [
                        [
                            'type'      => 2,
                            'style'     => 1,
                            'label'     => '♂️ Hombre',
                            'custom_id' => 'btn_reg_hombre',
                        ],
                        [
                            'type'      => 2,
                            'style'     => 1,
                            'label'     => '♀️ Mujer',
                            'custom_id' => 'btn_reg_mujer',
                        ],
                        [
                            'type'      => 2,
                            'style'     => 2,
                            'label'     => '⚧ Otro / No Binario',
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
    public static function introEdicion(int $coinBalance, int $cost): array
    {
        return [
            'type' => 4,
            'data' => [
                'flags'  => 64,
                'embeds' => [[
                    'title'       => '✏️ Editar tu Perfil MUDRAIS',
                    'description' => implode("\n", [
                        "Editar tus **Datos Básicos** no tiene costo.",
                        "Editar tu **Ficha de Arquetipo** tiene un costo de **{$cost} monedas**.",
                        '',
                        "Tu saldo actual: **{$coinBalance} monedas**.",
                        '',
                        'Selecciona qué parte de tu perfil deseas modificar.',
                    ]),
                    'color' => self::COLOR_BLUE,
                    'footer' => ['text' => 'MUDRAIS · Sistema de Emparejamiento de Rol'],
                ]],
                'components' => [[
                    'type'       => 1,
                    'components' => [
                        [
                            'type'      => 2,
                            'style'     => 2,
                            'label'     => '👤 Editar Datos Básicos',
                            'custom_id' => 'btn_abrir_modal_1_edicion',
                        ],
                        [
                            'type'      => 2,
                            'style'     => 1,
                            'label'     => '🎭 Ficha de Arquetipo',
                            'custom_id' => 'btn_abrir_modal_2',
                        ]
                    ],
                ]],
            ],
        ];
    }

    /**
     * Embed para jugadores con datos básicos guardados pero sin perfil de arquetipo en el vault actual.
     * Salta Step 1 e invoca directamente btn_abrir_modal_2 (creación gratuita).
     */
    public static function introCompletarArquetipo(): array
    {
        return [
            'type' => 4,
            'data' => [
                'flags'  => 64,
                'embeds' => [[
                    'title'       => '📋 Completa tu Ficha de Arquetipo',
                    'description' => implode("\n", [
                        'Tus **datos básicos ya están guardados**.',
                        '',
                        'Aún no tienes una ficha de arquetipo para este servidor.',
                        '**Completarla es gratuito.** Haz clic para continuar.',
                    ]),
                    'color'  => self::COLOR_GREEN,
                    'footer' => ['text' => 'MUDRAIS · Sistema de Emparejamiento de Rol'],
                ]],
                'components' => [[
                    'type'       => 1,
                    'components' => [[
                        'type'      => 2,
                        'style'     => 1,
                        'label'     => '🎭 Completar Ficha de Arquetipo',
                        'custom_id' => 'btn_abrir_modal_2',
                    ]],
                ]],
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
            'title'       => "📋 Registro en Progreso ({$nextPage}/{$totalPages})",
            'description' => implode("\n", [
                '¡Excelente! Hemos guardado la parte anterior.',
                '',
                'Este arquetipo requiere **más datos específicos**.',
                'Haz clic abajo para continuar.',
            ]),
            'color'  => self::COLOR_BLUE,
            'footer' => ['text' => 'MUDRAIS · Sistema de Emparejamiento de Rol'],
        ]],
        'components' => [[
            'type'       => 1,
            'components' => [[
                'type'      => 2,
                'style'     => 1,
                'label'     => "Continuar Parte " . ($nextPage + 1) . " →",
                'custom_id' => "btn_abrir_modal_2:{$nextPage}",
            ]],
        ]],
    ];
}

/**
 * Mensaje puente que reemplaza el embed de bienvenida tras un Step 1 exitoso.
...
     * Aparece mientras el usuario no ha abierto el Modal 2 todavía.
     */
    public static function puenteStep2(): array
    {
        return [
            'content' => '',
            'embeds'  => [[
                'title'       => '✅ Paso 1 Completado',
                'description' => implode("\n", [
                    'Tus datos han sido guardados.',
                    '',
                    'Haz clic abajo para continuar con tu **estilo de escritura y preferencias**.',
                ]),
                'color'  => self::COLOR_BLUE,
                'footer' => ['text' => 'MUDRAIS · Sistema de Emparejamiento de Rol'],
            ]],
            'components' => [[
                'type'       => 1,
                'components' => [[
                    'type'      => 2,
                    'style'     => 1,
                    'label'     => 'Continuar al Paso 2 →',
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
                'title'       => '⚠️ Error en el Paso 1',
                'description' => $errorMessage . "\n\nHaz clic abajo para corregirlo.",
                'color'       => self::COLOR_RED,
            ]],
            'components' => [[
                'type'       => 1,
                'components' => [[
                    'type'      => 2,
                    'style'     => 4,
                    'label'     => '🔁 Corregir Paso 1',
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
                'title'       => '⚠️ Error en el Paso 2',
                'description' => $errorMessage . "\n\nHaz clic abajo para corregirlo.",
                'color'       => self::COLOR_RED,
            ]],
            'components' => [[
                'type'       => 1,
                'components' => [[
                    'type'      => 2,
                    'style'     => 4,
                    'label'     => '🔁 Corregir Paso 2',
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
                'title'       => '🎉 ¡Perfil MUDRAIS Creado!',
                'description' => implode("\n", [
                    "¡Bienvenido, **{$username}**! Tu ficha está lista.",
                    '',
                    'Ahora puedes usar `/create` para encontrar compañeros de rol o iniciar una nueva partida.',
                    '',
                    '**Siguiente paso recomendado:** Completa el Vault Tutorial para desbloquear todas las funciones.',
                ]),
                'color'  => self::COLOR_GREEN,
                'footer' => ['text' => 'MUDRAIS · Sistema de Emparejamiento de Rol'],
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
                'title'       => '✅ Ficha Actualizada',
                'description' => implode("\n", [
                    "Tu perfil ha sido actualizado, **{$username}**.",
                    '',
                    "Saldo restante: **{$coinsRemaining} monedas**.",
                ]),
                'color'  => self::COLOR_GOLD,
                'footer' => ['text' => 'MUDRAIS · Sistema de Emparejamiento de Rol'],
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
                    'title'       => '⚠️ Error en el Paso 1',
                    'description' => $errorMessage . "\n\nHaz clic abajo para corregirlo.",
                    'color'       => self::COLOR_RED,
                ]],
                'components' => [[
                    'type'       => 1,
                    'components' => [[
                        'type'      => 2,
                        'style'     => 4,
                        'label'     => '🔁 Corregir Paso 1',
                        'custom_id' => 'btn_retry_modal_1',
                    ]],
                ]],
            ],
        ];
    }
}

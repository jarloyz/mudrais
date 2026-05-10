<?php

namespace App\Infrastructure\Discord\Embeds;

class VaultApprovalEmbeds
{
    private const COLOR_GOLD   = 16766720; // #FFD700
    private const COLOR_GREEN  = 5763719;  // #57F287
    private const COLOR_RED    = 15548997; // #ED4245
    private const COLOR_BLUE   = 3447003;  // #3498DB

    /**
     * Embed de aprobación con botones (pendingId en custom_id de botones)
     * Devuelve el array listo para pasar como \$extra a sendFollowUp().
     *
     * @param array{name_es: string, name_en: string, optimized_text_en: string, semantic_tag_query: string} $optimized
     * @param string[] $tagSlugs
     */
    public static function approvalRequest(array $optimized, array $tagSlugs, string $pendingId): array
    {
        $previewText = mb_strlen($optimized['optimized_text_en']) > 500
            ? mb_substr($optimized['optimized_text_en'], 0, 500) . '...'
            : $optimized['optimized_text_en'];

        return [
            'embeds' => [[
                'title'       => 'Vault Preview — Revisión Semántica',
                'color'       => self::COLOR_GOLD,
                'fields'      => [
                    [
                        'name'   => 'Nombre (ES)',
                        'value'  => $optimized['name_es'],
                        'inline' => true,
                    ],
                    [
                        'name'   => 'Nombre (EN)',
                        'value'  => $optimized['name_en'],
                        'inline' => true,
                    ],
                    [
                        'name'   => 'Texto Optimizado (Vectorial)',
                        'value'  => "```\n{$previewText}\n```",
                        'inline' => false,
                    ],
                    [
                        'name'   => 'Tags (Taxonomía)',
                        'value'  => implode(', ', $tagSlugs) ?: 'Ninguno',
                        'inline' => false,
                    ],
                ],
                'footer'      => ['text' => '⏱ Esta vista previa expira en 15 minutos.'],
            ]],
            'components' => [[
                'type'       => 1,
                'components' => [
                    [
                        'type'      => 2,
                        'style'     => 3, // Verde
                        'label'     => '✅ Aceptar y Guardar',
                        'custom_id' => "vault_approve:{$pendingId}",
                    ],
                    [
                        'type'      => 2,
                        'style'     => 4, // Rojo
                        'label'     => '❌ Rechazar',
                        'custom_id' => "vault_reject:{$pendingId}",
                    ],
                ],
            ]],
        ];
    }

    /**
     * Para type:7 inmediato al hacer clic en aprobar
     */
    public static function processing(): array
    {
        return [
            'embeds' => [[
                'title'       => '⏳ Procesando...',
                'description' => 'Estamos creando y vectorizando tu Vault. Esto tomará unos segundos.',
                'color'       => self::COLOR_BLUE,
            ]],
            'components' => [], // Quita los botones
        ];
    }

    /**
     * Para type:7 inmediato al rechazar
     */
    public static function rejected(): array
    {
        return [
            'embeds' => [[
                'title'       => '❌ Creación Cancelada',
                'description' => 'El Vault no ha sido creado. Los datos han sido descartados de forma segura.',
                'color'       => self::COLOR_RED,
            ]],
            'components' => [],
        ];
    }

    /**
     * Enviado como follow-up por el ApprovalJob tras crear el vault
     */
    public static function approved(string $channelId): array
    {
        return [
            'embeds' => [[
                'title'       => '✅ Vault Creado Exitosamente',
                'description' => "Tu nuevo espacio de rol está listo: <#{$channelId}>\n¡Disfruta de la aventura!",
                'color'       => self::COLOR_GREEN,
            ]],
            'components' => [],
        ];
    }
}

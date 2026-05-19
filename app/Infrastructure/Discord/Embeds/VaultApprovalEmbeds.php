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
     * Devuelve el array listo para pasar como $extra a sendFollowUp().
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
                'title'  => __('discord.vault_approval_preview_title'),
                'color'  => self::COLOR_GOLD,
                'fields' => [
                    [
                        'name'   => __('discord.vault_approval_field_name_es'),
                        'value'  => $optimized['name_es'],
                        'inline' => true,
                    ],
                    [
                        'name'   => __('discord.vault_approval_field_name_en'),
                        'value'  => $optimized['name_en'],
                        'inline' => true,
                    ],
                    [
                        'name'   => __('discord.vault_approval_field_optimized'),
                        'value'  => "```\n{$previewText}\n```",
                        'inline' => false,
                    ],
                    [
                        'name'   => __('discord.vault_approval_field_tags'),
                        'value'  => implode(', ', $tagSlugs) ?: __('discord.vault_approval_tags_none'),
                        'inline' => false,
                    ],
                ],
                'footer' => ['text' => __('discord.vault_approval_footer_expires')],
            ]],
            'components' => [[
                'type'       => 1,
                'components' => [
                    [
                        'type'      => 2,
                        'style'     => 3,
                        'label'     => __('discord.vault_approval_btn_approve'),
                        'custom_id' => "vault_approve:{$pendingId}",
                    ],
                    [
                        'type'      => 2,
                        'style'     => 4,
                        'label'     => __('discord.vault_approval_btn_reject'),
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
                'title'       => __('discord.vault_processing_title'),
                'description' => __('discord.vault_processing_desc'),
                'color'       => self::COLOR_BLUE,
            ]],
            'components' => [],
        ];
    }

    /**
     * Para type:7 inmediato al rechazar
     */
    public static function rejected(): array
    {
        return [
            'embeds' => [[
                'title'       => __('discord.vault_rejected_title'),
                'description' => __('discord.vault_rejected_desc'),
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
                'title'       => __('discord.vault_approved_title'),
                'description' => __('discord.vault_approved_desc', ['channel' => $channelId]),
                'color'       => self::COLOR_GREEN,
            ]],
            'components' => [],
        ];
    }
}

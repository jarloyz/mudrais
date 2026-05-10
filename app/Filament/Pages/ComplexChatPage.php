<?php

namespace App\Filament\Pages;

class ComplexChatPage extends BaseChatPage
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Chat Completo';

    protected static string | \UnitEnum | null $navigationGroup = 'Roleplay';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'chat-completo';

    protected string $view = 'filament.pages.complex-chat-page';

    protected function experienceKey(): string
    {
        return 'complex';
    }

    protected function experienceConfig(): array
    {
        return [
            'key' => 'complex',
            'eyebrow' => 'Chat Completo',
            'title' => 'Continuidad, branching y runtime narrativo completo.',
            'description' => 'Esta superficie separa el flujo complejo del MVP: continuidad, QA opcional, commits y contexto multiagente.',
            'tone' => 'fuchsia',
            'chat_title' => 'Chat completo',
            'chat_description' => 'Usa continuidad activa y pipeline completo para escenas largas o ramificadas.',
            'show_scene_create' => false,
            'show_character_import' => false,
            'show_continuity_id' => true,
            'show_mode_selector' => true,
            'runtime_label' => 'Runtime completo',
            'runtime_badge' => 'Writer + QA + Director + Statekeeper',
            'send_label' => 'Turno completo',
        ];
    }
}

<?php

namespace App\Filament\Pages;

class ChatPage extends BaseChatPage
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Chat Simple';

    protected static string | \UnitEnum | null $navigationGroup = 'Roleplay';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'chat';

    protected string $view = 'filament.pages.chat-page';
    
    protected function experienceKey(): string
    {
        return 'simple';
    }

    protected function experienceConfig(): array
    {
        return [
            'key' => 'simple',
            'eyebrow' => 'Chat Runtime',
            'title' => 'Conversación en vivo con streaming narrativo.',
            'description' => 'Flujo principal del MVP para roleplay: vault, escena, personajes, memoria simple y writer en vivo.',
            'tone' => 'amber',
            'chat_title' => 'Chat simple',
            'chat_description' => 'Optimizado para crear o continuar una escena con writer + summarizer.',
            'show_scene_create' => true,
            'show_character_import' => true,
            'show_continuity_id' => false,
            'show_mode_selector' => false,
            'runtime_label' => 'Runtime simple',
            'runtime_badge' => 'Writer + Summarizer',
            'send_label' => 'Enviar',
        ];
    }
}

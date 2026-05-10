<?php

namespace App\Filament\Resources\Archetypes\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ArchetypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identidad')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(100),

                        TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->disabled()
                            ->unique(ignoreRecord: true)
                            ->maxLength(100)
                            ->helperText('Generado por el pipeline. Identificador URL-friendly.'),
                    ]),

                Textarea::make('summary')
                    ->label('Resumen')
                    ->rows(4)
                    ->columnSpanFull()
                    ->helperText('Descripción del arquetipo. Se usará para generar el vector semántico en el matchmaking hub.'),

                Section::make('Vectorización')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('qdrant_vector_name')
                            ->label('Nombre vector Qdrant (legacy)')
                            ->maxLength(100)
                            ->disabled()
                            ->helperText('Identificador heredado. El nuevo vector se gestiona automáticamente.'),

                        Toggle::make('is_hub_indexed')
                            ->label('Indexado en Hub')
                            ->disabled()
                            ->helperText('Se actualiza automáticamente al guardar.'),

                        Placeholder::make('archetype_hub_qdrant_id')
                            ->label('Qdrant ID')
                            ->content(fn ($record) => $record?->archetype_hub_qdrant_id ?? '—'),
                    ]),

                Section::make('Prompts de IA')
                    ->description('Fragmentos inyectados en el template base de cada agente según el arquetipo.')
                    ->collapsed()
                    ->schema([
                        Repeater::make('prompts')
                            ->relationship()
                            ->schema([
                                Select::make('agent_type')
                                    ->label('Agente')
                                    ->options([
                                        'context_injection' => 'Context Injection ({archetype_prompt_injection})',
                                        'gatekeeper'        => 'Gatekeeper (extractor)',
                                        'optimizer'         => 'Optimizer (embeddeable, legacy)',
                                        'player_profile'    => 'Player Profile (inyectable en template base)',
                                        'vault'             => 'Vault (inyectable en template base)',
                                    ])
                                    ->required()
                                    ->distinct()
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                    ->columnSpan(1),

                                Textarea::make('system_prompt')
                                    ->label('Prompt')
                                    ->rows(6)
                                    ->required()
                                    ->columnSpan(2),
                            ])
                            ->columns(3)
                            ->addActionLabel('+ Añadir prompt')
                            ->columnSpanFull(),
                    ]),

                Section::make('Preview estructura Discord')
                    ->collapsed()
                    ->description('Vista previa de cómo el bot mostraría los campos. Hard = sin LLM, Soft = procesado por LLM.')
                    ->schema([
                        Placeholder::make('discord_preview')
                            ->label('')
                            ->columnSpanFull()
                            ->content(function ($record): string {
                                if (! $record?->exists) return 'Guarda el arquetipo primero.';

                                $output = [];
                                foreach (['registration', 'activities_vibe', 'avatar_context'] as $ctx) {
                                    $mutators = $record->mutators()->where('context', $ctx)->orderBy('sort_order')->get();
                                    if ($mutators->isEmpty()) continue;

                                    $output[] = "── CONTEXTO: {$ctx} ──";
                                    $inline = $mutators->filter(fn($m) => blank($m->modal_group));
                                    $groups = $mutators->filter(fn($m) => filled($m->modal_group))->groupBy('modal_group');

                                    foreach ($inline as $m) {
                                        $mode = "[{$m->storage_mode->value}]";
                                        $req  = $m->is_required ? ' *' : '';
                                        $output[] = "  • {$mode} {$m->field_label}{$req}  ({$m->field_type})";
                                    }
                                    foreach ($groups as $group => $gMutators) {
                                        $output[] = "  [BOTÓN: {$group}] → Modal:";
                                        foreach ($gMutators as $m) {
                                            $mode = "[{$m->storage_mode->value}]";
                                            $req  = $m->is_required ? ' *' : '';
                                            $output[] = "    • {$mode} {$m->field_label}{$req}  ({$m->field_type})";
                                        }
                                    }
                                }
                                return implode("\n", $output) ?: 'Sin mutators configurados.';
                            }),
                    ]),
            ]);
    }
}

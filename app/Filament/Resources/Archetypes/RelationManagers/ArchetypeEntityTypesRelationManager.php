<?php

namespace App\Filament\Resources\Archetypes\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ArchetypeEntityTypesRelationManager extends RelationManager
{
    protected static string $relationship = 'entityTypes';

    protected static ?string $title = 'Tipos de Entidad';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('entity')
                    ->label('Entidad')
                    ->options([
                        'avatar'   => 'Avatar (personaje / contexto)',
                        'activity' => 'Actividad',
                    ])
                    ->required()
                    ->live(),

                Select::make('avatar_purpose')
                    ->label('Propósito del Avatar')
                    ->options([
                        'role'    => 'Role — su vector busca player profiles',
                        'context' => 'Context — su vector modifica el query por blending (30%)',
                    ])
                    ->nullable()
                    ->helperText('Null = avatar de propósito general, no usado en /search_team.')
                    ->visible(fn (Get $get) => $get('entity') === 'avatar'),

                TextInput::make('type_key')
                    ->label('Clave interna')
                    ->required()
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->helperText('Ej: character, partida, one_shot'),

                TextInput::make('type_label')
                    ->label('Label visible')
                    ->required()
                    ->maxLength(80),

                TextInput::make('sort_order')
                    ->label('Orden')
                    ->numeric()
                    ->default(0),

                Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true),

                Textarea::make('description')
                    ->label('Descripción')
                    ->rows(2)
                    ->columnSpanFull(),

                Textarea::make('system_prompt')
                    ->label('Prompt del Optimizador LLM')
                    ->rows(12)
                    ->columnSpanFull()
                    ->helperText(
                        'Avatars usan {context_data_json} · Activities usan {user_soft_data_json} · ' .
                        'Ambos pueden coexistir en activities con contexto específico · ' .
                        '{archetype_prompt_injection} → reglas de dominio (context_injection del archetype) · ' .
                        '{vault_context} → solo activities con Vault · ' .
                        'Output obligatorio: {"optimized_text_en":"...","semantic_tag_query":"..."}'
                    ),

                Repeater::make('matching_filters')
                    ->label('Filtros de Matchmaking')
                    ->helperText('Pre-filtro aplicado sobre PlayerArchetypeProfile.content_raw antes del semantic search. Solo visible en activities.')
                    ->schema([
                        TextInput::make('profile_field')
                            ->label('Campo del perfil')
                            ->placeholder('Ej: is_writer')
                            ->helperText('field_key del mutador raw en el perfil del jugador')
                            ->required(),

                        Select::make('operator')
                            ->label('Operador')
                            ->options(['eq' => 'Igual a (eq)'])
                            ->default('eq')
                            ->required(),

                        TextInput::make('value')
                            ->label('Valor')
                            ->placeholder('Ej: true')
                            ->helperText('Para booleanos usar: true / false')
                            ->required(),
                    ])
                    ->columns(3)
                    ->addActionLabel('Agregar filtro')
                    ->defaultItems(0)
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => $get('entity') === 'activity'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type_label')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('entity')
                    ->label('Entidad')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'avatar'   => 'success',
                        'activity' => 'warning',
                        default    => 'gray',
                    }),

                TextColumn::make('avatar_purpose')
                    ->label('Purpose')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'role'    => 'success',
                        'context' => 'purple',
                        default   => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state) => $state ?? '—'),

                TextColumn::make('type_label')
                    ->label('Tipo')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('type_key')
                    ->label('Clave')
                    ->copyable()
                    ->color('gray'),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),

                TextColumn::make('matching_filters')
                    ->label('Filtros')
                    ->formatStateUsing(fn ($state) => $state ? count($state) . ' filtro(s)' : '—')
                    ->color(fn ($state) => $state ? 'warning' : 'gray'),

                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

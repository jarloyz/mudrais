<?php

namespace App\Filament\Resources\Activities\Schemas;

use App\Domains\Matchmaking\Enums\ActivitySearchDirection;
use App\Domains\Matchmaking\Enums\ActivityStatus;
use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\ArchetypeMutator;
use App\Domains\Narrative\Models\Activity;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ActivityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identidad')
                ->columns(2)
                ->schema([
                    Select::make('vault_id')
                        ->relationship('vault', 'name')
                        ->required()
                        ->searchable(),
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255),
                    Select::make('status')
                        ->options(ActivityStatus::options())
                        ->default(ActivityStatus::PENDING->value),
                    Select::make('location_id')
                        ->relationship('location', 'name')
                        ->nullable()
                        ->searchable(),
                    Textarea::make('objective')
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('activity_description')
                        ->rows(3)
                        ->columnSpanFull(),
                    Toggle::make('requires_avatar')
                        ->label('Requiere avatar')
                        ->default(false),
                ]),

            Section::make('Modo de Búsqueda')
                ->columns(2)
                ->collapsible()
                ->schema([
                    Select::make('search_direction')
                        ->label('Dirección de búsqueda')
                        ->options([
                            ActivitySearchDirection::OUTBOUND->value => 'Outbound — jugadores encuentran esta actividad',
                            ActivitySearchDirection::INBOUND->value  => 'Inbound — la actividad busca jugadores (usa ctx1 como criterio)',
                            ActivitySearchDirection::BOTH->value     => 'Both — bidireccional',
                        ])
                        ->default(ActivitySearchDirection::OUTBOUND->value)
                        ->required()
                        ->live()
                        ->helperText('Inbound requiere un avatar ctx1 indexado para funcionar.'),

                    TextInput::make('required_slots')
                        ->label('Slots requeridos (team search)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(50)
                        ->nullable()
                        ->helperText('Número total de personas que necesita el equipo. Dejar vacío para búsqueda individual.')
                        ->visible(fn(Get $get) => in_array($get('search_direction'), [
                            ActivitySearchDirection::INBOUND->value,
                            ActivitySearchDirection::BOTH->value,
                        ])),

                    Select::make('parent_activity_id')
                        ->label('Actividad padre (slot de equipo)')
                        ->options(fn(Get $get) => Activity::query()
                            ->whereNotNull('required_slots')
                            ->where('vault_id', $get('vault_id'))
                            ->pluck('title', 'id')
                        )
                        ->searchable()
                        ->nullable()
                        ->helperText('Si esta actividad es un slot de un equipo, selecciona la actividad padre.')
                        ->visible(fn(Get $get) => in_array($get('search_direction'), [
                            ActivitySearchDirection::INBOUND->value,
                            ActivitySearchDirection::BOTH->value,
                        ])),
                ]),

            Section::make('Tipo de Actividad')
                ->schema([
                    Select::make('archetype_entity_type_id')
                        ->label('Tipo de actividad')
                        ->options(fn() => ArchetypeEntityType::query()
                            ->where('entity', 'activity')
                            ->active()
                            ->orderBy('sort_order')
                            ->with('archetype')
                            ->get()
                            ->mapWithKeys(fn($et) => [$et->id => "[{$et->archetype->name}] {$et->type_label}"])
                        )
                        ->searchable()
                        ->nullable()
                        ->live()
                        ->afterStateUpdated(fn($set) => $set('content_raw', []))
                        ->helperText('Determina qué campos Activities Vibe aparecen abajo.'),
                ]),

            Section::make('Activities Vibe (Campos del Esquema)')
                ->visible(fn(Get $get) => filled($get('archetype_entity_type_id')))
                ->description('Campos "semantic" o "both" son procesados por LLM al indexar. Los "raw" se guardan literalmente.')
                ->schema(fn(Get $get): array => self::buildDynamicFields($get, 'activities_vibe'))
        ]);
    }

    public static function buildDynamicFields(Get $get, string $context): array
    {
        $entityTypeId = $get('archetype_entity_type_id');
        if (blank($entityTypeId)) {
            return [];
        }

        $entityType = ArchetypeEntityType::find($entityTypeId);
        if (! $entityType) {
            return [];
        }

        $mutators = ArchetypeMutator::where('archetype_entity_type_id', $entityType->id)
            ->where('context', $context)
            ->orderBy('sort_order')
            ->get();

        if ($mutators->isEmpty()) {
            return [
                Placeholder::make('no_fields')
                    ->label('')
                    ->content('Sin campos configurados para este arquetipo.')
            ];
        }

        $components = [];
        foreach ($mutators as $m) {
            $key = "content_raw.{$m->field_key}";
            $label = $m->field_label;
            $hint = "[{$m->storage_mode->value}] " . $m->field_placeholder;

            $component = match ($m->field_type) {
                'text_short' => TextInput::make($key)
                    ->label($label)
                    ->minLength($m->options['min_length'] ?? 0)
                    ->maxLength($m->options['max_length'] ?? 45)
                    ->helperText($hint),
                'text_long' => \Filament\Forms\Components\Textarea::make($key)
                    ->label($label)
                    ->minLength($m->options['min_length'] ?? 0)
                    ->maxLength($m->options['max_length'] ?? 1000)
                    ->rows(4)
                    ->helperText($hint),
                'text' => TextInput::make($key)->label($label)->helperText($hint),
                'select' => Select::make($key)
                    ->label($label)
                    ->options(
                        collect($m->options['items'] ?? [])
                            ->mapWithKeys(fn ($o) => [$o['value'] => $o['label']])
                    )
                    ->placeholder($m->options['placeholder'] ?? null)
                    ->multiple(($m->options['max_values'] ?? 1) > 1)
                    ->helperText($hint),
                'number', 'range' => TextInput::make($key)
                    ->label($label)
                    ->numeric()
                    ->minValue($m->options['min'] ?? null)
                    ->maxValue($m->options['max'] ?? null)
                    ->helperText($hint),
                'boolean' => Toggle::make($key)->label($label)->helperText($hint),
                default => TextInput::make($key)->label($label)->helperText($hint),
            };

            if ($m->is_required) {
                $component->required();
            }

            $components[] = $component;
        }

        return $components;
    }
}

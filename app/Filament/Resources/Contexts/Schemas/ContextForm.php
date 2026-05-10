<?php

namespace App\Filament\Resources\Contexts\Schemas;

use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\ArchetypeMutator;
use Filament\Schemas\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ContextForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identidad')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required(),
                    Select::make('vault_id')
                        ->relationship('vault', 'name')
                        ->required()
                        ->searchable(),
                    Toggle::make('is_lfg')
                        ->label('Looking for Group')
                        ->default(false),
                ]),

            Section::make('Tipo de Contexto')
                ->schema([
                    Select::make('archetype_entity_type_id')
                        ->label('Tipo de contexto')
                        ->options(fn() => ArchetypeEntityType::query()
                            ->where('entity', 'avatar')
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
                ]),

            Section::make('Avatar Context (Campos del Esquema)')
                ->visible(fn(Get $get) => filled($get('archetype_entity_type_id')))
                ->description('Campos "semantic" o "both" son procesados por LLM al indexar.')
                ->schema(fn(Get $get): array => self::buildDynamicFields($get, 'avatar_context'))
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

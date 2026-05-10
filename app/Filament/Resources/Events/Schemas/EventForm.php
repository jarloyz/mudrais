<?php

namespace App\Filament\Resources\Events\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class EventForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('id')
                    ->label('Event ID')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->columnSpanFull(),
                Select::make('vault_id')
                    ->relationship('vault', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live(),
                Select::make('location_id')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload(),
                Select::make('quest_id')
                    ->relationship('quest', 'title')
                    ->searchable()
                    ->preload(),
                TextInput::make('title')
                    ->required(),
                Select::make('scene_id')
                    ->relationship('scene', 'title')
                    ->searchable()
                    ->preload(),
                TextInput::make('context_id'),
                TextInput::make('date_label'),
                Select::make('subject_character_id')
                    ->relationship('subjectCharacter', 'name')
                    ->searchable()
                    ->preload(),
                Select::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'completed' => 'Completed',
                    ])
                    ->required()
                    ->default('active'),
                TextInput::make('importance')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('cooldown_turns')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('last_fired_turn')
                    ->numeric()
                    ->default(0),
                TextInput::make('source'),

                Textarea::make('summary')
                    ->columnSpanFull(),
                Textarea::make('brief')
                    ->columnSpanFull(),
                Textarea::make('detail')
                    ->columnSpanFull(),

                Repeater::make('conditions')
                    ->relationship()
                    ->schema([
                        Select::make('scope_type')
                            ->options([
                                'scene' => 'Activity',
                                'location' => 'Location',
                                'character' => 'Avatar',
                                'quest' => 'Quest',
                                'state' => 'State',
                                'tag' => 'Tag',
                            ])
                            ->required(),
                        Select::make('operator')
                            ->options([
                                'eq' => 'Equals',
                                'in' => 'In',
                                'contains' => 'Contains',
                                'exists' => 'Exists',
                                'not_exists' => 'Not Exists',
                            ])
                            ->required(),
                        TextInput::make('value_text'),
                        TextInput::make('weight')->numeric()->default(1),
                        Toggle::make('required')->default(false),
                        Toggle::make('active')->default(true),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Repeater::make('effects')
                    ->relationship()
                    ->schema([
                        Select::make('effect_type')
                            ->options([
                                'state_change' => 'State Change',
                                'quest_status' => 'Quest Status',
                                'note' => 'Note',
                            ])
                            ->required()
                            ->default('state_change')
                            ->live(),
                        Select::make('kind')
                            ->options(['state' => 'State'])
                            ->default('state')
                            ->hidden(fn ($get) => $get('effect_type') === 'quest_status'),
                        Select::make('scope_type')
                            ->options([
                                'global' => 'Global',
                                'scene' => 'Activity',
                                'location' => 'Location',
                                'character' => 'Avatar',
                                'event' => 'Event',
                            ])
                            ->required()
                            ->hidden(fn ($get) => $get('effect_type') === 'quest_status'),
                        TextInput::make('scope_id')
                            ->hidden(fn ($get) => $get('effect_type') === 'quest_status'),
                        TextInput::make('quest_id')
                            ->label('Target Quest ID')
                            ->placeholder('Si vacio, usa la del evento')
                            ->hidden(fn ($get) => $get('effect_type') !== 'quest_status')
                            ->afterStateHydrated(fn ($state, $set, $record) => $set('quest_id', $record?->payload_json['quest_id'] ?? null))
                            ->dehydrated(false),
                        Toggle::make('advance_step')
                            ->label('Avanzar Step')
                            ->hidden(fn ($get) => $get('effect_type') !== 'quest_status')
                            ->afterStateHydrated(fn ($state, $set, $record) => $set('advance_step', $record?->payload_json['advance_step'] ?? false))
                            ->dehydrated(false),
                        TextInput::make('new_stage_number')
                            ->label('Nuevo Stage')
                            ->numeric()
                            ->hidden(fn ($get) => $get('effect_type') !== 'quest_status')
                            ->afterStateHydrated(fn ($state, $set, $record) => $set('new_stage_number', $record?->payload_json['new_stage_number'] ?? null))
                            ->dehydrated(false),
                        Textarea::make('change_text')
                            ->label(fn ($get) => $get('effect_type') === 'quest_status' ? 'Nuevo Estado (active/completed/failed)' : 'Texto del cambio')
                            ->columnSpanFull(),
                        TextInput::make('severity')->numeric()->default(1)->minValue(1)->maxValue(5),
                        Toggle::make('active')->default(true),
                    ])
                    ->mutateRelationshipDataBeforeSaveUsing(function (array $data): array {
                        if (($data['effect_type'] ?? null) === 'quest_status') {
                            $data['payload_json'] = [
                                'quest_id' => $data['quest_id'] ?? null,
                                'advance_step' => (bool) ($data['advance_step'] ?? false),
                                'new_stage_number' => $data['new_stage_number'] ?? null,
                            ];
                        }
                        return $data;
                    })
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}

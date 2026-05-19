<?php

namespace App\Filament\Resources\Archetypes\RelationManagers;

use App\Domains\Matchmaking\Enums\MutatorStorageMode;
use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Jobs\Discord\GenerateInterviewOpeningJob;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ArchetypeMutatorsRelationManager extends RelationManager
{
    protected static string $relationship = 'mutators';

    protected static ?string $title = 'Mutators de Formulario';

    private const PROTECTED_KEYS = ['red_lines', 'yellow_lines', 'preferences', 'style'];

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('context')
                    ->label('Contexto')
                    ->options([
                        'registration'    => 'Registro (/registro Discord)',
                        'activities_vibe' => 'Activities Vibe',
                        'avatar_context'  => 'Avatar Context',
                    ])
                    ->required()
                    ->live(),

                Select::make('archetype_entity_type_id')
                    ->label('Tipo de entidad')
                    ->options(function (RelationManager $livewire): array {
                        return ArchetypeEntityType::query()
                            ->where('archetype_id', $livewire->getOwnerRecord()->id)
                            ->orderBy('sort_order')
                            ->get()
                            ->mapWithKeys(fn ($et) => [
                                $et->id => "[{$et->entity}] {$et->type_label}",
                            ])
                            ->all();
                    })
                    ->nullable()
                    ->visible(fn (Get $get) => in_array($get('context'), ['activities_vibe', 'avatar_context']))
                    ->required(fn (Get $get) => in_array($get('context'), ['activities_vibe', 'avatar_context']))
                    ->helperText('Qué tipo específico verá este campo (ej: Personaje, Locación). Obligatorio para activities_vibe y avatar_context.'),

                Select::make('field_type')
                    ->label('Tipo de campo')
                    ->options([
                        'text_short' => 'Texto corto (Discord SHORT)',
                        'text_long'  => 'Texto largo (Discord PARAGRAPH)',
                        'text'       => 'Texto libre (legacy)',
                        'select'     => 'Selección',
                        'range'      => 'Rango numérico',
                        'boolean'    => 'Sí / No',
                    ])
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($set) => $set('options', [])),

                TextInput::make('field_key')
                    ->label('Clave interna')
                    ->required()
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->helperText('snake_case. Ej: compromiso, nivel_habilidad')
                    ->disabled(fn ($record) => $record && in_array($record->field_key, self::PROTECTED_KEYS))
                    ->dehydrated(fn ($record) => ! $record || ! in_array($record->field_key, self::PROTECTED_KEYS)),

                TextInput::make('field_label')
                    ->label('Label visible')
                    ->required()
                    ->maxLength(80),

                TextInput::make('field_placeholder')
                    ->label('Ejemplo / placeholder')
                    ->nullable()
                    ->maxLength(255)
                    ->helperText('Texto de ejemplo que aparece dentro del campo. Ej: "Ej: combate táctico, rol intenso"'),

                TextInput::make('modal_group')
                    ->nullable()
                    ->maxLength(50)
                    ->regex('/^[a-z][a-z0-9_]*$/')
                    ->helperText('snake_case. Vacío = campo inline en embed. Con valor = agrupado en modal button.'),

                Select::make('storage_mode')
                    ->options(\App\Domains\Matchmaking\Enums\MutatorStorageMode::options())
                    ->required()
                    ->default('raw')
                    ->helperText('raw = content_raw sin LLM | semantic = LLM | both = ambos'),

                Toggle::make('is_required')
                    ->label('Obligatorio')
                    ->default(false),

                TextInput::make('sort_order')
                    ->label('Orden')
                    ->numeric()
                    ->default(0),

                Fieldset::make('Configuración de Select (Discord)')
                    ->columnSpanFull()
                    ->hidden(fn (Get $get) => $get('field_type') !== 'select')
                    ->schema([
                        TextInput::make('options.placeholder')
                            ->label('Placeholder del select')
                            ->maxLength(150)
                            ->placeholder('Ej: Elige tu nivel de compromiso...')
                            ->helperText('Texto que muestra Discord cuando no hay selección. Máx. 150 chars.')
                            ->live()
                            ->columnSpanFull(),

                        TextInput::make('options.min_values')
                            ->label('Mín. selecciones')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(25)
                            ->default(1)
                            ->live()
                            ->helperText('Mínimo de opciones que debe elegir el usuario.'),

                        TextInput::make('options.max_values')
                            ->label('Máx. selecciones')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(25)
                            ->default(1)
                            ->live()
                            ->helperText('Máximo de opciones. Discord permite hasta 25.'),

                        Repeater::make('options.items')
                            ->label('Opciones (máx. 25)')
                            ->columnSpanFull()
                            ->columns(3)
                            ->defaultItems(0)
                            ->maxItems(25)
                            ->reorderable()
                            ->addActionLabel('Añadir opción')
                            ->schema([
                                TextInput::make('label')
                                    ->label('Label')
                                    ->required()
                                    ->maxLength(100)
                                    ->helperText('Visible en Discord. Máx. 100 chars.'),

                                TextInput::make('value')
                                    ->label('Value (interno)')
                                    ->required()
                                    ->regex('/^[a-z][a-z0-9_-]*$/')
                                    ->maxLength(100)
                                    ->helperText('snake_case. Máx. 100 chars.'),

                                TextInput::make('description')
                                    ->label('Descripción')
                                    ->maxLength(100)
                                    ->nullable()
                                    ->helperText('Subtexto bajo el label en Discord. Opcional.'),
                            ]),
                    ]),

                Fieldset::make('Configuración de texto (Discord)')
                    ->columnSpanFull()
                    ->columns(2)
                    ->hidden(fn (Get $get) => ! in_array($get('field_type'), ['text_short', 'text_long']))
                    ->schema([
                        TextInput::make('options.min_length')
                            ->label('Longitud mínima')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(4000)
                            ->default(0)
                            ->live()
                            ->helperText('Discord: min_length. 0 = sin mínimo.'),

                        TextInput::make('options.max_length')
                            ->label('Longitud máxima')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(4000)
                            ->default(fn (Get $get) => $get('field_type') === 'text_short' ? 45 : 1000)
                            ->live()
                            ->helperText('Discord: max_length. SHORT recomendado ≤ 45, PARAGRAPH ≤ 4000.'),
                    ]),

                Fieldset::make('Configuración de rango')
                    ->columnSpanFull()
                    ->columns(2)
                    ->hidden(fn (Get $get) => $get('field_type') !== 'range')
                    ->schema([
                        TextInput::make('options.min')
                            ->label('Valor mínimo')
                            ->numeric()
                            ->required(fn (Get $get) => $get('field_type') === 'range')
                            ->live()
                            ->helperText('Ej: 1'),

                        TextInput::make('options.min_example')
                            ->label('Ejemplo del mínimo')
                            ->maxLength(100)
                            ->live()
                            ->helperText('Ej: Casual, sin compromisos'),

                        TextInput::make('options.max')
                            ->label('Valor máximo')
                            ->numeric()
                            ->required(fn (Get $get) => $get('field_type') === 'range')
                            ->live()
                            ->helperText('Ej: 10'),

                        TextInput::make('options.max_example')
                            ->label('Ejemplo del máximo')
                            ->maxLength(100)
                            ->live()
                            ->helperText('Ej: Hardcore, máximo compromiso'),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('field_label')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('context')
                    ->label('Contexto')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'registration'    => 'warning',
                        'activities_vibe' => 'info',
                        'avatar_context'  => 'success',
                        default           => 'gray',
                    }),

                TextColumn::make('entityType.type_label')
                    ->label('Tipo')
                    ->badge()
                    ->color('primary')
                    ->placeholder('— todos —'),

                TextColumn::make('modal_group')
                    ->label('Grupo')
                    ->placeholder('— inline —')
                    ->badge()
                    ->color('purple'),

                TextColumn::make('storage_mode')
                    ->label('Storage')
                    ->badge()
                    ->color(fn (\App\Domains\Matchmaking\Enums\MutatorStorageMode $state): string => match ($state) {
                        \App\Domains\Matchmaking\Enums\MutatorStorageMode::RAW      => 'gray',
                        \App\Domains\Matchmaking\Enums\MutatorStorageMode::SEMANTIC => 'info',
                        \App\Domains\Matchmaking\Enums\MutatorStorageMode::BOTH     => 'success',
                    }),

                TextColumn::make('field_label')
                    ->label('Label')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('field_key')
                    ->label('Clave')
                    ->copyable()
                    ->color('gray'),

                TextColumn::make('field_type')
                    ->label('Tipo')
                    ->badge(),

                IconColumn::make('is_required')
                    ->label('Req.')
                    ->boolean(),

                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make(),
                Action::make('generate_opening')
                    ->label('Generar Pregunta de Apertura')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Generar pregunta de apertura')
                    ->modalDescription('Se generará una nueva pregunta de apertura de entrevista basada en los mutadores de tipo texto de este arquetipo. La operación puede tardar unos segundos.')
                    ->action(function ($livewire) {
                        GenerateInterviewOpeningJob::dispatch($livewire->ownerRecord->id);
                    })
                    ->successNotificationTitle('Generando pregunta de apertura en cola...'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn ($record) => in_array($record->field_key, self::PROTECTED_KEYS)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

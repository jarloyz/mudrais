<?php

namespace App\Filament\Resources\AiProviders;

use App\Filament\Resources\AiProviders\Pages;
use App\Models\AiProvider;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AiProviderResource extends Resource
{
    protected static ?string $model = AiProvider::class;

    protected static string|\BackedEnum|null $navigationIcon    = 'heroicon-o-server';
    protected static string|\UnitEnum|null   $navigationGroup   = 'Sistema';
    protected static ?int                    $navigationSort     = 15;
    protected static ?string                 $modelLabel         = 'Proveedor LLM';
    protected static ?string                 $pluralModelLabel   = 'Proveedores LLM';
    protected static ?string                 $slug               = 'sistema/ai-providers';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nombre')
                ->required()
                ->maxLength(255)
                ->placeholder('OpenRouter, AMD Server…'),

            TextInput::make('slug')
                ->label('Slug (identificador)')
                ->required()
                ->maxLength(100)
                ->unique(ignoreRecord: true)
                ->placeholder('openrouter, amd…')
                ->helperText('Debe coincidir con el valor que se usa en HISTORIA_AI_PROVIDER.'),

            Select::make('driver')
                ->label('Driver')
                ->required()
                ->options([
                    'openai_compatible' => 'OpenAI-compatible (/v1/chat/completions)',
                    'anthropic'         => 'Anthropic (gestionado internamente)',
                    'ollama'            => 'Ollama (gestionado internamente)',
                    'google'            => 'Google AI (Gemini — endpoint OpenAI-compatible)',
                ])
                ->default('openai_compatible'),

            TextInput::make('base_url')
                ->label('URL base')
                ->required()
                ->maxLength(500)
                ->placeholder('https://openrouter.ai/api/v1')
                ->helperText('Sin barra final. Ej: http://134.199.199.46:8001/v1'),

            TextInput::make('default_model')
                ->label('Modelo por defecto')
                ->nullable()
                ->maxLength(255)
                ->placeholder('m-120b, openai/gpt-4o, …')
                ->helperText('ID del modelo a usar cuando este proveedor sirve un único modelo (servidores AMD por puerto). Deja vacío para proveedores multi-modelo.'),

            TextInput::make('api_key')
                ->label('API Key')
                ->password()
                ->revealable()
                ->nullable()
                ->maxLength(1000)
                ->helperText('Dejar vacío si el servidor no requiere autenticación.'),

            Textarea::make('description')
                ->label('Descripción')
                ->nullable()
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->badge()
                    ->color('primary')
                    ->searchable(),

                TextColumn::make('driver')
                    ->label('Driver')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'openai_compatible' => 'success',
                        'anthropic'         => 'warning',
                        'ollama'            => 'info',
                        'google'            => 'danger',
                        default             => 'gray',
                    }),

                TextColumn::make('base_url')
                    ->label('URL')
                    ->limit(40)
                    ->tooltip(fn (AiProvider $record) => $record->base_url),

                TextColumn::make('default_model')
                    ->label('Modelo')
                    ->placeholder('—')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make(),
                BulkActionGroup::make([]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAiProviders::route('/'),
            'create' => Pages\CreateAiProvider::route('/create'),
            'edit'   => Pages\EditAiProvider::route('/{record}/edit'),
        ];
    }
}

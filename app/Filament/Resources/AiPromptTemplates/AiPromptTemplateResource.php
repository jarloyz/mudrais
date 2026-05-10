<?php

namespace App\Filament\Resources\AiPromptTemplates;

use App\Filament\Resources\AiPromptTemplates\Pages;
use App\Models\AiPromptTemplate;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AiPromptTemplateResource extends Resource
{
    protected static ?string $model = AiPromptTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';
    protected static string|\UnitEnum|null   $navigationGroup = 'Sistema';
    protected static ?int                    $navigationSort = 20;
    protected static ?string                 $modelLabel = 'Template de Prompt';
    protected static ?string                 $pluralModelLabel = 'Templates de Prompts IA';
    protected static ?string                 $slug = 'sistema/ai-prompt-templates';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('key')
                ->label('Clave')
                ->content(fn ($record) => $record?->key ?? '—'),

            TextInput::make('description')
                ->label('Descripción')
                ->maxLength(255)
                ->columnSpanFull(),

            Textarea::make('body')
                ->label('Prompt base')
                ->rows(20)
                ->required()
                ->columnSpanFull()
                ->helperText('Usa {archetype_prompt_injection} para inyectar el fragmento específico del arquetipo, y {user_soft_data_json} para los datos del usuario.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Clave')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Descripción')
                    ->limit(60)
                    ->wrap(),

                TextColumn::make('body')
                    ->label('Preview')
                    ->limit(80)
                    ->wrap()
                    ->color('gray'),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ])
            ->defaultSort('key');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiPromptTemplates::route('/'),
            'edit'  => Pages\EditAiPromptTemplate::route('/{record}/edit'),
        ];
    }
}

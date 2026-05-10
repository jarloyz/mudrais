<?php

namespace App\Filament\Resources\Archetypes\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ArchetypePromptsRelationManager extends RelationManager
{
    protected static string $relationship = 'prompts';

    protected static ?string $title = 'Prompts de IA';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('agent_type')
                    ->label('Tipo de agente')
                    ->options([
                        'context_injection' => 'Context Injection (terminología de dominio, inyectable como {archetype_prompt_injection})',
                        'gatekeeper'        => 'Gatekeeper (extractor)',
                        'optimizer'         => 'Optimizer (embeddeable, legacy)',
                        'player_profile'    => 'Player Profile (inyectable en template base)',
                        'vault'             => 'Vault (inyectable en template base)',
                    ])
                    ->required(),

                Textarea::make('system_prompt')
                    ->label('System Prompt')
                    ->rows(12)
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('agent_type')
            ->columns([
                TextColumn::make('agent_type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'context_injection' => 'purple',
                        'gatekeeper'        => 'warning',
                        'optimizer'         => 'success',
                        'player_profile'    => 'info',
                        'vault'             => 'primary',
                        default             => 'gray',
                    }),

                TextColumn::make('system_prompt')
                    ->label('Prompt')
                    ->limit(80)
                    ->wrap(),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
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

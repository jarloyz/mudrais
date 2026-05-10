<?php

namespace App\Filament\Resources\Scenes\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CharactersRelationManager extends RelationManager
{
    protected static string $relationship = 'characters';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('scene_role')
                    ->label('Activity Role')
                    ->sortable(),
                TextColumn::make('controlled_by_user_id')
                    ->label('Controlled By')
                    ->getStateUsing(fn ($record) => $record->pivot->controlled_by_user_id ? \App\Models\User::find($record->pivot->controlled_by_user_id)->name : 'NPC')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make()
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        \Filament\Forms\Components\Select::make('scene_role')
                            ->options([
                                'player' => 'Player',
                                'npc' => 'NPC',
                                'guest' => 'Guest',
                            ])
                            ->default('npc')
                            ->required(),
                        \Filament\Forms\Components\Select::make('controlled_by_user_id')
                            ->label('Controlled By User')
                            ->options(\App\Models\User::pluck('name', 'id'))
                            ->searchable()
                            ->nullable(),
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->form([
                        \Filament\Forms\Components\Select::make('scene_role')
                            ->options([
                                'player' => 'Player',
                                'npc' => 'NPC',
                                'guest' => 'Guest',
                            ])
                            ->required(),
                        \Filament\Forms\Components\Select::make('controlled_by_user_id')
                            ->label('Controlled By User')
                            ->options(\App\Models\User::pluck('name', 'id'))
                            ->searchable()
                            ->nullable(),
                    ]),
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }
}

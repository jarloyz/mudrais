<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserProfileRelationManager extends RelationManager
{
    protected static string $relationship = 'profile';

    protected static ?string $title = 'Perfil';

    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('display_name')
                    ->label('Nombre de pantalla')
                    ->maxLength(100),
                Forms\Components\Textarea::make('bio')
                    ->label('Biografía')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('timezone')
                    ->label('Zona Horaria')
                    ->maxLength(50)
                    ->default('UTC'),
                Forms\Components\TextInput::make('locale')
                    ->label('Idioma')
                    ->maxLength(10)
                    ->default('es'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('display_name')
            ->columns([
                TextColumn::make('display_name')->label('Nombre de pantalla'),
                TextColumn::make('timezone')->label('Zona Horaria'),
                TextColumn::make('locale')->label('Idioma'),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

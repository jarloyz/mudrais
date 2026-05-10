<?php

namespace App\Filament\Resources\Archetypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ArchetypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('slug')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                IconColumn::make('is_hub_indexed')
                    ->label('Hub')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),

                TextColumn::make('mutators_count')
                    ->label('Mutators')
                    ->counts('mutators')
                    ->badge()
                    ->color('info'),

                TextColumn::make('entity_types_count')
                    ->label('Tipos')
                    ->counts('entityTypes')
                    ->badge()
                    ->color('info'),

                TextColumn::make('guilds_count')
                    ->label('Guilds')
                    ->counts('guilds')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }
}

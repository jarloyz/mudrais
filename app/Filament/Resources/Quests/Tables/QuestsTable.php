<?php

namespace App\Filament\Resources\Quests\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QuestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable(),
                TextColumn::make('vault.name')
                    ->searchable(),
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('steps_count')
                    ->label('Steps')
                    ->counts('steps')
                    ->badge(),
                TextColumn::make('steps_preview')
                    ->label('Inicio')
                    ->state(function ($record): ?string {
                        $step = $record->steps()->orderBy('stage_number')->first();

                        if (! $step) {
                            return null;
                        }

                        return $step->stage_number.': '.$step->description;
                    })
                    ->limit(80)
                    ->wrap(),
                TextColumn::make('type')
                    ->searchable(),
                TextColumn::make('status')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
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
            ]);
    }
}

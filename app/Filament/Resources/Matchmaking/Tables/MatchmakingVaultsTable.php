<?php

namespace App\Filament\Resources\Matchmaking\Tables;

use App\Filament\Resources\Vaults\VaultResource;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MatchmakingVaultsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('archetype.name')
                    ->label('Arquetipo')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                IconColumn::make('is_hub_indexed')
                    ->label('Hub')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),

                IconColumn::make('is_public')
                    ->label('Público')
                    ->boolean(),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active'   => 'success',
                        'archived' => 'danger',
                        default    => 'gray',
                    }),

                TextColumn::make('memberships_count')
                    ->label('Miembros')
                    ->counts('memberships')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('archetype')
                    ->label('Arquetipo')
                    ->relationship('archetype', 'name'),

                SelectFilter::make('is_hub_indexed')
                    ->label('Estado hub')
                    ->options([
                        '1' => 'Indexado',
                        '0' => 'Sin indexar',
                    ]),
            ])
            ->recordActions([
                Action::make('editar')
                    ->label('Editar')
                    ->icon('heroicon-o-pencil')
                    ->url(fn ($record) => VaultResource::getUrl('edit', ['record' => $record])),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}

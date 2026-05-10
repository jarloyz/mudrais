<?php

namespace App\Filament\Resources\ArchetypeDrafts\Tables;

use App\Domains\Matchmaking\Enums\ArchetypeDraftStatus;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ArchetypeDraftsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('input_name')
                    ->label('Nombre crudo')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name_en')
                    ->label('Nombre EN')
                    ->searchable(),

                TextColumn::make('slug')
                    ->copyable()
                    ->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ArchetypeDraftStatus $state): string => match ($state) {
                        ArchetypeDraftStatus::PENDING      => 'gray',
                        ArchetypeDraftStatus::PROCESSING   => 'warning',
                        ArchetypeDraftStatus::NEEDS_REVIEW => 'primary',
                        ArchetypeDraftStatus::APPROVED     => 'success',
                        ArchetypeDraftStatus::REJECTED     => 'danger',
                        ArchetypeDraftStatus::ERROR        => 'danger',
                    }),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ArchetypeDraftStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make()
                    ->visible(fn ($record) => in_array($record->status, [
                        ArchetypeDraftStatus::PENDING,
                        ArchetypeDraftStatus::REJECTED,
                        ArchetypeDraftStatus::ERROR,
                    ])),
            ]);
    }
}

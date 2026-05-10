<?php

namespace App\Filament\Resources\Contexts\Tables;

use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

class ContextsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('vault.name')
                    ->label('Vault'),
                TextColumn::make('entityType.type_label')
                    ->badge()
                    ->color('success')
                    ->label('Tipo'),
                IconColumn::make('is_lfg')
                    ->boolean(),
                IconColumn::make('is_hub_indexed')
                    ->label('Indexado')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Vaults\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QuestsRelationManager extends RelationManager
{
    protected static string $relationship = 'quests';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('type')
                    ->required()
                    ->default('main'),
                TextInput::make('status')
                    ->required()
                    ->default('active'),
                Repeater::make('steps')
                    ->relationship()
                    ->label('Steps')
                    ->defaultItems(3)
                    ->reorderable(false)
                    ->collapsible()
                    ->schema([
                        TextInput::make('stage_number')
                            ->label('Stage')
                            ->required()
                            ->numeric(),
                        Textarea::make('description')
                            ->required()
                            ->rows(2)
                            ->columnSpanFull(),
                        Toggle::make('is_optional')
                            ->label('Opcional')
                            ->default(false),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
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

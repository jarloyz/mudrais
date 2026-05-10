<?php

namespace App\Filament\Resources\QdrantLogs;

use App\Filament\Resources\QdrantLogs\Pages;
use App\Models\QdrantLog;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QdrantLogResource extends Resource
{
    protected static ?string $model = QdrantLog::class;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-circle-stack';
    protected static string|\UnitEnum|null   $navigationGroup = 'Sistema';
    protected static ?int                    $navigationSort  = 26;
    protected static ?string                 $modelLabel      = 'Log de Qdrant';
    protected static ?string                 $pluralModelLabel = 'Logs de Qdrant';
    protected static ?string                 $slug            = 'sistema/qdrant-logs';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),

                TextColumn::make('collection_name')
                    ->label('Colección')
                    ->badge()
                    ->color('info')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('operation')
                    ->label('Operación')
                    ->badge()
                    ->color('primary')
                    ->searchable(),

                TextColumn::make('latency_ms')
                    ->label('Latencia (ms)')
                    ->numeric(decimalPlaces: 0)
                    ->sortable(),

                TextColumn::make('matches_count')
                    ->label('Resultados')
                    ->numeric()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'error'   => 'danger',
                        default   => 'gray',
                    }),

                TextColumn::make('top_score')
                    ->label('Score top')
                    ->numeric(decimalPlaces: 4)
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('query_text')
                    ->label('Query')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record?->query_text)
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('top_result')
                    ->label('Resultado top')
                    ->limit(80)
                    ->tooltip(fn ($record) => $record?->top_result)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQdrantLogs::route('/'),
        ];
    }
}

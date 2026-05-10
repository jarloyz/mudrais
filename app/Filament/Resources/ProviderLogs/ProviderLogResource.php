<?php

namespace App\Filament\Resources\ProviderLogs;

use App\Filament\Resources\ProviderLogs\Pages;
use App\Models\ProviderLog;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProviderLogResource extends Resource
{
    protected static ?string $model = ProviderLog::class;

    protected static string|\BackedEnum|null $navigationIcon  = 'heroicon-o-chart-bar';
    protected static string|\UnitEnum|null   $navigationGroup = 'Sistema';
    protected static ?int                    $navigationSort  = 25;
    protected static ?string                 $modelLabel      = 'Log de Proveedor';
    protected static ?string                 $pluralModelLabel = 'Logs de Proveedores LLM';
    protected static ?string                 $slug            = 'sistema/provider-logs';

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

                TextColumn::make('agent')
                    ->label('Agente')
                    ->badge()
                    ->color('primary')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('model')
                    ->label('Modelo')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                TextColumn::make('latency_ms')
                    ->label('Latencia (ms)')
                    ->numeric(decimalPlaces: 0)
                    ->sortable(),

                TextColumn::make('total_tokens')
                    ->label('Tokens')
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
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProviderLogs::route('/'),
        ];
    }
}

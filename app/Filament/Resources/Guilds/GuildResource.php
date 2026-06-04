<?php

namespace App\Filament\Resources\Guilds;

use App\Domains\Community\Models\Guild;
use App\Filament\Resources\Guilds\Pages\EditGuild;
use App\Filament\Resources\Guilds\Pages\ListGuilds;
use App\Models\AppSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;

/**
 * Recurso Filament para gestión de Guilds de Discord.
 * Permite ver, editar y controlar el acceso de cada guild al bot.
 */
class GuildResource extends Resource
{
    protected static ?string $model = Guild::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?string $navigationLabel = 'Guilds';

    protected static string|\UnitEnum|null $navigationGroup = 'Comunidad';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Forms\Components\TextInput::make('discord_guild_id')
                ->label('Discord Guild ID')
                ->required()
                ->maxLength(255),

            \Filament\Forms\Components\Toggle::make('is_active')
                ->label('Activa')
                ->default(true),

            \Filament\Forms\Components\Toggle::make('is_bot_allowed')
                ->label('Bot habilitado')
                ->helperText('Si está desactivado, la guild no podrá usar /create-vault.')
                ->default(AppSetting::bool('guild_bot_allowed_default', true)),

            \Filament\Forms\Components\TextInput::make('plan_tier')
                ->label('Plan Tier')
                ->numeric()
                ->default(1),

            \Filament\Forms\Components\TextInput::make('profile_quota')
                ->label('Cuota de perfiles')
                ->numeric()
                ->default(50),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('discord_guild_id')
                    ->label('Discord Guild ID')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('owner_discord_id')
                    ->label('Owner ID')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Activa')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),

                ToggleColumn::make('is_bot_allowed')
                    ->label('Bot habilitado')
                    ->onColor('success')
                    ->offColor('danger')
                    ->afterStateUpdated(function (Guild $record, bool $state): void {
                        Log::info('[GuildResource] is_bot_allowed actualizado', [
                            'guild_id'       => $record->id,
                            'discord_guild_id' => $record->discord_guild_id,
                            'is_bot_allowed' => $state,
                        ]);
                    }),

                TextColumn::make('plan_tier')
                    ->label('Plan')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('vaults_count')
                    ->label('Vaults')
                    ->counts('vaults')
                    ->badge()
                    ->color('info'),

                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGuilds::route('/'),
            'edit'  => EditGuild::route('/{record}/edit'),
        ];
    }
}

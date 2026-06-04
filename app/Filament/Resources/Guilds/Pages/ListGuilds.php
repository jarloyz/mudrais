<?php

namespace App\Filament\Resources\Guilds\Pages;

use App\Filament\Resources\Guilds\GuildResource;
use App\Models\AppSetting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Log;

/**
 * Página de listado de Guilds con acción para cambiar el default de nuevas guilds.
 */
class ListGuilds extends ListRecords
{
    protected static string $resource = GuildResource::class;

    protected function getHeaderActions(): array
    {
        $currentDefault = AppSetting::bool('guild_bot_allowed_default', true);
        $newDefault      = ! $currentDefault;
        $label           = $currentDefault
            ? 'Default nuevas guilds: PERMITIDAS (click para bloquear)'
            : 'Default nuevas guilds: BLOQUEADAS (click para permitir)';

        return [
            Action::make('toggle_guild_default')
                ->label($label)
                ->icon($currentDefault ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle')
                ->color($currentDefault ? 'success' : 'danger')
                ->requiresConfirmation()
                ->modalHeading('Cambiar default para nuevas guilds')
                ->modalDescription(
                    $currentDefault
                        ? 'Las nuevas guilds pasarán a estar BLOQUEADAS por defecto. ¿Confirmar?'
                        : 'Las nuevas guilds pasarán a estar PERMITIDAS por defecto. ¿Confirmar?'
                )
                ->action(function () use ($newDefault): void {
                    AppSetting::set('guild_bot_allowed_default', $newDefault ? 'true' : 'false');

                    Log::info('[ListGuilds] Default de guild_bot_allowed_default actualizado', [
                        'new_value' => $newDefault,
                    ]);

                    Notification::make()
                        ->title('Default actualizado')
                        ->body($newDefault ? 'Nuevas guilds: PERMITIDAS' : 'Nuevas guilds: BLOQUEADAS')
                        ->color($newDefault ? 'success' : 'danger')
                        ->send();
                }),
        ];
    }
}

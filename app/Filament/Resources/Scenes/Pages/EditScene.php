<?php

namespace App\Filament\Resources\Scenes\Pages;

use App\Application\Services\BootstrapSceneService;
use App\Filament\Resources\Scenes\SceneResource;
use App\Infrastructure\Persistence\Eloquent\Models\CharacterRecord;
use App\Infrastructure\Persistence\Eloquent\Models\SceneRecord;
use App\Models\Continuity;
use App\Models\SceneActiveContinuity;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditScene extends EditRecord
{
    protected static string $resource = SceneResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start_game')
                ->label('Comenzar Partida')
                ->color('success')
                ->icon('heroicon-o-play')
                ->hidden(fn () => $this->record->status !== 'draft')
                ->requiresConfirmation()
                ->action(function (BootstrapSceneService $service) {
                    $record = $this->record;

                    // 1. Validaciones
                    if (!$record->location_id) {
                        Notification::make()
                            ->title('Error de Validación')
                            ->body('La escena debe tener una locación asignada para comenzar.')
                            ->danger()
                            ->send();
                        return;
                    }

                    if ($record->characters()->count() === 0) {
                        Notification::make()
                            ->title('Error de Validación')
                            ->body('La escena debe tener al menos un personaje asignado para comenzar.')
                            ->danger()
                            ->send();
                        return;
                    }

                    // 2. Transición de estado en transacción corta (solo DB, sin llamadas externas)
                    $sceneRecord = null;
                    $charRecord = null;

                    DB::transaction(function () use ($record, &$sceneRecord, &$charRecord) {
                        $record->update(['status' => 'ready']);

                        // Asegurar Continuidad Activa
                        $activeCont = SceneActiveContinuity::query()->where('activity_id', $record->id)->first();
                        if (!$activeCont) {
                            $continuityId = $record->id . '_main';
                            Continuity::query()->updateOrCreate(
                                ['id' => $continuityId],
                                [
                                    'label' => "Continuidad de " . $record->title,
                                    'status' => 'active',
                                ]
                            );
                            SceneActiveContinuity::query()->create([
                                'activity_id' => $record->id,
                                'continuity_id' => $continuityId,
                            ]);
                        }

                        // Preparar los Records para el servicio (fuera de la transacción se llama a la IA)
                        $sceneRecord = SceneRecord::query()->where('id', $record->id)->first();

                        $playerCharacter = $record->characters()
                            ->wherePivot('scene_role', 'player')
                            ->first();

                        if ($playerCharacter) {
                            $charRecord = CharacterRecord::query()->where('id', $playerCharacter->id)->first();
                        }
                    });

                    // 3. Invocar BootstrapSceneService fuera de la transacción (llamada a IA)
                    $service->generateOpening($sceneRecord, $charRecord);

                    Notification::make()
                        ->title('Partida Iniciada')
                        ->body('La escena ha sido transicionada a "Ready" y se ha generado la apertura narrativa.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                }),
            DeleteAction::make(),
        ];
    }
}

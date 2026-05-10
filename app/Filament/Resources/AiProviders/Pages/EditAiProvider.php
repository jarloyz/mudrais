<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use Illuminate\Support\Facades\Cache;
use Filament\Resources\Pages\EditRecord;

class EditAiProvider extends EditRecord
{
    protected static string $resource = AiProviderResource::class;

    protected function afterSave(): void
    {
        // Invalidar caché del preset editado para que el gateway lo recargue.
        Cache::forget('ai_provider_'.$this->record->slug);
        Cache::forget('ai_active_provider');
    }
}

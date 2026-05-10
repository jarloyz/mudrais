<?php

namespace App\Filament\Resources\AiPromptTemplates\Pages;

use App\Filament\Resources\AiPromptTemplates\AiPromptTemplateResource;
use App\Models\AiPromptTemplate;
use Filament\Resources\Pages\EditRecord;

class EditAiPromptTemplate extends EditRecord
{
    protected static string $resource = AiPromptTemplateResource::class;

    protected function afterSave(): void
    {
        AiPromptTemplate::invalidateCache($this->record->key);
    }
}

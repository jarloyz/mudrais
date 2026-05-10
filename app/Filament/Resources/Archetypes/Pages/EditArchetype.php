<?php

namespace App\Filament\Resources\Archetypes\Pages;

use App\Filament\Resources\Archetypes\ArchetypeResource;
use App\Jobs\IndexArchetypeJob;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditArchetype extends EditRecord
{
    protected static string $resource = ArchetypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        // Re-indexar solo si cambió algún campo semántico
        $dirty = $this->record->wasChanged(['name', 'slug', 'summary']);
        $tagsChanged = $this->record->tags()->count() !== count($this->record->getOriginal('tags') ?? []);

        if ($dirty || $tagsChanged) {
            IndexArchetypeJob::dispatch($this->record->id);
        }
    }
}

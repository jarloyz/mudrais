<?php

namespace App\Filament\Resources\Quests\Pages;

use App\Filament\Resources\Quests\QuestResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditQuest extends EditRecord
{
    protected static string $resource = QuestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

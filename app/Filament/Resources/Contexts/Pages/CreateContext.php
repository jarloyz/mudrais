<?php

namespace App\Filament\Resources\Contexts\Pages;

use App\Filament\Resources\Contexts\ContextResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContext extends CreateRecord
{
    protected static string $resource = ContextResource::class;
}

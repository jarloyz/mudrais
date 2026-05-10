<?php

namespace App\Filament\Resources\QdrantLogs\Pages;

use App\Filament\Resources\QdrantLogs\QdrantLogResource;
use Filament\Resources\Pages\ListRecords;

class ListQdrantLogs extends ListRecords
{
    protected static string $resource = QdrantLogResource::class;
}

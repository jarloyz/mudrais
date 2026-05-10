<?php

namespace App\Filament\Resources\Matchmaking\Pages;

use App\Filament\Resources\Matchmaking\MatchmakingVaultResource;
use Filament\Resources\Pages\ListRecords;

class ListMatchmakingVaults extends ListRecords
{
    protected static string $resource = MatchmakingVaultResource::class;
}

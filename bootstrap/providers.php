<?php

return [
    App\Domains\Community\CommunityServiceProvider::class,
    App\Domains\Intelligence\IntelligenceServiceProvider::class,
    App\Domains\Matchmaking\MatchmakingServiceProvider::class,
    App\Domains\Narrative\NarrativeServiceProvider::class,
    App\Providers\AgentServiceProvider::class,
    App\Providers\AppServiceProvider::class,
    App\Providers\CharacterServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\PlayerPanelProvider::class,
    App\Providers\HorizonServiceProvider::class,
];

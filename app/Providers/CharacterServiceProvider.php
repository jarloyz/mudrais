<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Ports\CharacterRepositoryInterface;
use App\Infrastructure\Character\SqlCharacterRepository;
use Illuminate\Support\ServiceProvider;

class CharacterServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CharacterRepositoryInterface::class, SqlCharacterRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

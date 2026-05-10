<x-filament-panels::page>

    @if (session('error'))
        <x-filament::section>
            <div class="flex items-center gap-3 text-danger-600 dark:text-danger-400 font-medium">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="w-5 h-5" />
                {{ session('error') }}
            </div>
        </x-filament::section>
    @endif

    {{-- Perfil --}}
    <x-filament::section>
        <x-slot name="heading">Perfil del Jugador</x-slot>
        <x-slot name="description">Detalles de tu cuenta vinculada a Discord.</x-slot>

        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-950 dark:text-white">{{ $player->username }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 font-mono">
                    {{ $player->discord_id }}
                </p>
            </div>
            <x-filament::badge :color="$player->is_active ? 'success' : 'danger'" size="lg">
                {{ $player->is_active ? 'Activo' : 'Inactivo' }}
            </x-filament::badge>
        </div>
    </x-filament::section>

    {{-- Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
        <x-filament::section>
            <div class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest flex items-center gap-1.5">
                    <x-filament::icon icon="heroicon-m-bolt" class="w-4 h-4 text-success-500" />
                    Energía
                </span>
                <span class="text-3xl font-bold text-success-600 dark:text-success-400">{{ $player->energy }}</span>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest flex items-center gap-1.5">
                    <x-filament::icon icon="heroicon-m-circle-stack" class="w-4 h-4 text-warning-500" />
                    Monedas
                </span>
                <span class="text-3xl font-bold text-warning-600 dark:text-warning-400">{{ $player->coin }}</span>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest flex items-center gap-1.5">
                    <x-filament::icon icon="heroicon-m-trophy" class="w-4 h-4 text-info-500" />
                    ELO
                </span>
                <span class="text-3xl font-bold text-info-600 dark:text-info-400">{{ $player->elo }}</span>
            </div>
        </x-filament::section>
    </div>

    {{-- Instalación del bot --}}
    <x-filament::section icon="heroicon-o-server-stack">
        <x-slot name="heading">Instalar el Bot en tu Servidor</x-slot>
        <x-slot name="description">
            Agrega el bot a tu servidor de Discord para activar los comandos de roleplay
            y administrar tu comunidad desde ahí.
        </x-slot>

        <x-filament::button
            tag="a"
            href="{{ route('invite.bot.redirect') }}"
            icon="heroicon-m-plus"
            size="lg"
        >
            Instalar Bot en mi servidor
        </x-filament::button>
    </x-filament::section>

</x-filament-panels::page>

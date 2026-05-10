<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — {{ config('app.name') }}</title>
    @filamentStyles
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="antialiased bg-gray-50 text-gray-950 dark:bg-gray-950 dark:text-white min-h-screen flex flex-col">

    <nav class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 px-6 py-4 flex items-center justify-between shadow-sm">
        <span class="font-semibold text-indigo-400 tracking-wide flex items-center gap-2">
            <x-filament::icon icon="heroicon-o-shield-check" class="w-6 h-6" />
            {{ config('app.name') }}
        </span>
        <form method="POST" action="{{ route('discord.logout') }}">
            @csrf
            <x-filament::button type="submit" color="danger" variant="text" icon="heroicon-m-arrow-right-on-rectangle">
                Cerrar sesión
            </x-filament::button>
        </form>
    </nav>

    <main class="flex-1 max-w-5xl mx-auto w-full px-6 py-12 flex flex-col gap-8">

        @if (session('error'))
            <x-filament::section class="border-red-600 bg-red-500/10">
                <div class="flex items-center gap-3 text-red-600 dark:text-red-400 font-medium">
                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="w-6 h-6" />
                    {{ session('error') }}
                </div>
            </x-filament::section>
        @endif

        <div>
            <h1 class="text-3xl font-bold tracking-tight text-gray-950 dark:text-white">Dashboard</h1>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                Gestiona tu cuenta y la instalación del bot en tu servidor.
            </p>
        </div>

        <x-filament::section>
            <x-slot name="heading">Perfil del Jugador</x-slot>
            <x-slot name="description">Detalles de tu cuenta vinculada a Discord.</x-slot>

            <div class="flex items-center gap-4">
                <div class="flex-1">
                    <h2 class="text-2xl font-bold">{{ $player->username }}</h2>
                    <p class="text-gray-500 dark:text-gray-400 mt-1">
                        Discord ID: <span class="font-mono">{{ $player->discord_id }}</span>
                    </p>
                </div>
                <x-filament::badge :color="$player->is_active ? 'success' : 'danger'" size="lg">
                    {{ $player->is_active ? 'Activo' : 'Inactivo' }}
                </x-filament::badge>
            </div>
        </x-filament::section>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            <x-filament::section>
                <div class="flex flex-col gap-2">
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-bolt" class="w-4 h-4 text-emerald-500" /> Energía
                    </span>
                    <span class="text-3xl font-bold text-emerald-500">{{ $player->energy }}</span>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex flex-col gap-2">
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-circle-stack" class="w-4 h-4 text-amber-500" /> Monedas
                    </span>
                    <span class="text-3xl font-bold text-amber-500">{{ $player->coin }}</span>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex flex-col gap-2">
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400 flex items-center gap-2">
                        <x-filament::icon icon="heroicon-m-trophy" class="w-4 h-4 text-sky-500" /> ELO
                    </span>
                    <span class="text-3xl font-bold text-sky-500">{{ $player->elo }}</span>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section icon="heroicon-o-server-stack">
            <x-slot name="heading">Instalación del Bot</x-slot>
            <x-slot name="description">
                Agrega el bot a tu servidor de Discord para activar los comandos de roleplay y administrar tu comunidad desde ahí.
            </x-slot>

            <div class="mt-4">
                <x-filament::button
                    tag="a"
                    href="{{ route('invite.bot.redirect') }}"
                    icon="heroicon-m-plus"
                    color="primary"
                    size="lg"
                >
                    Instalar Bot en mi servidor
                </x-filament::button>
            </div>
        </x-filament::section>

    </main>

    @filamentScripts
</body>
</html>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bot Instalado — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-950 text-white min-h-screen flex flex-col items-center justify-center px-6">

    <div class="max-w-md w-full rounded-2xl bg-gray-900 border border-gray-800 p-10 text-center">

        <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center
                    rounded-full bg-emerald-500/10 border border-emerald-500/30">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-emerald-400"
                 fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
        </div>

        <h1 class="text-2xl font-bold text-white mb-2">
            {{ session('was_created') ? '¡Bot instalado!' : '¡Servidor ya registrado!' }}
        </h1>

        @if (session('guild_discord_id'))
            <p class="text-sm text-gray-400 mb-6">
                Servidor: <span class="text-gray-200">{{ session('guild_discord_id') }}</span>
            </p>
        @endif

        <p class="text-sm text-gray-500 mb-8">
            El bot ya esta activo en tu servidor. Puedes comenzar a usar los comandos
            de roleplay desde Discord.
        </p>

        <a href="{{ route('discord.dashboard') }}"
           class="inline-flex items-center justify-center rounded-lg bg-indigo-600
                  hover:bg-indigo-500 px-6 py-3 text-sm font-semibold text-white
                  transition-colors w-full">
            Volver al Dashboard
        </a>

    </div>

</body>
</html>

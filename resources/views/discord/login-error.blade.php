<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Error de Login — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-950 text-white min-h-screen flex flex-col items-center justify-center px-6">

    <div class="max-w-md w-full rounded-2xl bg-gray-900 border border-red-900/50 p-10 text-center">

        <div class="mx-auto mb-6 flex h-16 w-16 items-center justify-center
                    rounded-full bg-red-500/10 border border-red-500/30">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-red-400"
                 fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </div>

        <h1 class="text-2xl font-bold text-white mb-2">Error de autenticacion</h1>

        @if (session('error'))
            <p class="text-sm text-red-300 mb-6">{{ session('error') }}</p>
        @else
            <p class="text-sm text-gray-400 mb-6">
                No fue posible completar la autenticacion con Discord.
            </p>
        @endif

        <a href="{{ route('auth.discord.redirect') }}"
           class="inline-flex items-center justify-center rounded-lg bg-indigo-600
                  hover:bg-indigo-500 px-6 py-3 text-sm font-semibold text-white
                  transition-colors w-full">
            Intentar nuevamente
        </a>

    </div>

</body>
</html>

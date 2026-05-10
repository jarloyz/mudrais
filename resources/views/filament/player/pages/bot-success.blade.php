<x-filament-panels::page>

    <x-filament::section>
        <div class="flex flex-col items-center text-center gap-6 py-6">

            <div class="flex h-20 w-20 items-center justify-center rounded-full
                        bg-success-500/10 border border-success-500/30">
                <x-filament::icon
                    icon="heroicon-o-check-circle"
                    class="w-10 h-10 text-success-500"
                />
            </div>

            <div>
                <h2 class="text-2xl font-bold text-gray-950 dark:text-white">
                    {{ session('was_created') ? '¡Bot instalado correctamente!' : '¡Servidor ya registrado!' }}
                </h2>

                @if (session('guild_discord_id'))
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        Servidor: <span class="font-mono text-gray-700 dark:text-gray-300">{{ session('guild_discord_id') }}</span>
                    </p>
                @endif

                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400 max-w-sm mx-auto">
                    El bot ya está activo. Puedes comenzar a usar los comandos de roleplay desde Discord.
                </p>
            </div>

            <x-filament::button
                tag="a"
                href="{{ \App\Filament\Player\Pages\DiscordDashboard::getUrl() }}"
                icon="heroicon-m-home"
            >
                Volver al Dashboard
            </x-filament::button>

        </div>
    </x-filament::section>

</x-filament-panels::page>

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
        <x-slot name="heading">{{ __('player.dashboard_profile_heading') }}</x-slot>
        <x-slot name="description">{{ __('player.dashboard_profile_description') }}</x-slot>

        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-950 dark:text-white">{{ $player->username }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 font-mono">
                    {{ $player->discord_id }}
                </p>
            </div>
            <x-filament::badge :color="$player->is_active ? 'success' : 'danger'" size="lg">
                {{ $player->is_active ? __('player.dashboard_badge_active') : __('player.dashboard_badge_inactive') }}
            </x-filament::badge>
        </div>
    </x-filament::section>

    {{-- Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
        <x-filament::section>
            <div class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest flex items-center gap-1.5">
                    <x-filament::icon icon="heroicon-m-bolt" class="w-4 h-4 text-success-500" />
                    {{ __('player.dashboard_stat_energy') }}
                </span>
                <span class="text-3xl font-bold text-success-600 dark:text-success-400">{{ $player->energy }}</span>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest flex items-center gap-1.5">
                    <x-filament::icon icon="heroicon-m-circle-stack" class="w-4 h-4 text-warning-500" />
                    {{ __('player.dashboard_stat_coins') }}
                </span>
                <span class="text-3xl font-bold text-warning-600 dark:text-warning-400">{{ $player->coin }}</span>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="flex flex-col gap-1">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-widest flex items-center gap-1.5">
                    <x-filament::icon icon="heroicon-m-trophy" class="w-4 h-4 text-info-500" />
                    {{ __('player.dashboard_stat_elo') }}
                </span>
                <span class="text-3xl font-bold text-info-600 dark:text-info-400">{{ $player->elo }}</span>
            </div>
        </x-filament::section>
    </div>

    {{-- Instalación de bots --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">

        <x-filament::section icon="heroicon-o-server-stack">
            <x-slot name="heading">{{ __('player.dashboard_bot_main_heading') }}</x-slot>
            <x-slot name="description">{{ __('player.dashboard_bot_main_description') }}</x-slot>

            <x-filament::button
                tag="a"
                href="{{ route('invite.bot.redirect') }}"
                icon="heroicon-m-plus"
                size="lg"
            >
                {{ __('player.dashboard_bot_main_btn') }}
            </x-filament::button>
        </x-filament::section>

        <x-filament::section icon="heroicon-o-microphone">
            <x-slot name="heading">{{ __('player.dashboard_bot_voice_heading') }}</x-slot>
            <x-slot name="description">
                {!! __('player.dashboard_bot_voice_description', [
                    'command' => '<code class="font-mono text-xs bg-gray-100 dark:bg-gray-800 px-1 rounded">/voice-interview</code>',
                ]) !!}
            </x-slot>

            <div class="flex flex-col gap-2">
                <x-filament::button
                    icon="heroicon-m-microphone"
                    color="gray"
                    size="lg"
                    disabled
                >
                    {{ __('player.dashboard_bot_voice_btn') }}
                </x-filament::button>
                <span class="text-xs text-gray-400 dark:text-gray-500">{{ __('player.dashboard_bot_voice_soon') }}</span>
            </div>
        </x-filament::section>

    </div>

</x-filament-panels::page>

<x-filament-panels::page>
    <x-historia.page-shell
        eyebrow="Historia Pipeline"
        title="Panel narrativo para operar escenas, continuidad y modelos desde Laravel."
        description="El panel vive en Filament con un tema propio; el chat usa Alpine puro solo donde aporta valor real: streaming y composición en vivo."
        tone="amber"
    >
        <x-slot:aside>
            <x-historia.stat label="Ruta principal" value="/app/chat" />
            <x-historia.stat label="Continuidad" value="Branch, rewind, checkout" />
            <x-historia.stat label="Config" value="Contexto y modelos por usuario" />
        </x-slot:aside>

        <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-4">
            <a href="{{ \App\Filament\Pages\ChatPage::getUrl(panel: 'admin') }}" class="hp-card block transition duration-200 hover:-translate-y-0.5 hover:shadow-lg">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-medium text-stone-500 dark:text-slate-400">Chat</div>
                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.2em] text-amber-700 dark:bg-amber-400/10 dark:text-amber-200">live</span>
                </div>
                <div class="mt-3 text-2xl font-semibold text-stone-950 dark:text-white">Conversación en vivo</div>
                <p class="mt-3 text-sm leading-7 text-stone-600 dark:text-slate-300">Streaming narrativo con contexto activo, timeline resumido y acceso directo a continuidad.</p>
            </a>

            <a href="{{ \App\Filament\Pages\TimelinePage::getUrl(panel: 'admin') }}" class="hp-card block transition duration-200 hover:-translate-y-0.5 hover:shadow-lg">
                <div class="text-sm font-medium text-stone-500 dark:text-slate-400">Timeline</div>
                <div class="mt-3 text-2xl font-semibold text-stone-950 dark:text-white">Historial y commits</div>
                <p class="mt-3 text-sm leading-7 text-stone-600 dark:text-slate-300">Turnos, commits y cambios de estado en una sola vista para revisar continuidad.</p>
            </a>

            <a href="{{ \App\Filament\Pages\ContinuityPage::getUrl(panel: 'admin') }}" class="hp-card block transition duration-200 hover:-translate-y-0.5 hover:shadow-lg">
                <div class="text-sm font-medium text-stone-500 dark:text-slate-400">Continuidad</div>
                <div class="mt-3 text-2xl font-semibold text-stone-950 dark:text-white">Branching y rewind</div>
                <p class="mt-3 text-sm leading-7 text-stone-600 dark:text-slate-300">Operaciones de rama, checkout y recuperación de estado para iterar sobre escenas.</p>
            </a>

            <a href="{{ \App\Filament\Pages\SettingsPage::getUrl(panel: 'admin') }}" class="hp-card block transition duration-200 hover:-translate-y-0.5 hover:shadow-lg">
                <div class="text-sm font-medium text-stone-500 dark:text-slate-400">Settings</div>
                <div class="mt-3 text-2xl font-semibold text-stone-950 dark:text-white">Contexto y runtime</div>
                <p class="mt-3 text-sm leading-7 text-stone-600 dark:text-slate-300">Configura el contexto compartido y los modelos de IA por usuario desde el panel.</p>
            </a>

            <a href="{{ \App\Filament\Resources\Vaults\VaultResource::getUrl('index', panel: 'admin') }}" class="hp-card block transition duration-200 hover:-translate-y-0.5 hover:shadow-lg">
                <div class="text-sm font-medium text-stone-500 dark:text-slate-400">Vaults</div>
                <div class="mt-3 text-2xl font-semibold text-stone-950 dark:text-white">Biblioteca de mundos</div>
                <p class="mt-3 text-sm leading-7 text-stone-600 dark:text-slate-300">Consulta los vaults disponibles y usa cada uno como contenedor de aventuras y canon.</p>
            </a>

            <a href="{{ \App\Filament\Resources\Characters\CharacterResource::getUrl('index', panel: 'admin') }}" class="hp-card block transition duration-200 hover:-translate-y-0.5 hover:shadow-lg">
                <div class="text-sm font-medium text-stone-500 dark:text-slate-400">Personajes</div>
                <div class="mt-3 text-2xl font-semibold text-stone-950 dark:text-white">Elenco y fichas</div>
                <p class="mt-3 text-sm leading-7 text-stone-600 dark:text-slate-300">Explora personajes por vault y detecta rápido qué fichas ya están listas para jugar.</p>
            </a>
        </div>
    </x-historia.page-shell>
</x-filament-panels::page>

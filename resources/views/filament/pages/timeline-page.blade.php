<x-filament-panels::page>
    <x-historia.page-shell
        eyebrow="Timeline"
        title="Historial completo de turnos, commits y cambios."
        description="Una vista de lectura rápida para auditar qué se generó, cuándo se comprometió y qué cambió en la continuidad activa."
        tone="sky"
    >
        <x-slot:aside>
            <form wire:submit="loadTimeline" class="hp-card hp-card-compact space-y-4">
                <label class="block text-sm">
                    <span class="mb-1 block text-stone-600 dark:text-slate-300">Scene ID</span>
                    <input wire:model="sceneId" class="hp-input" />
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block text-stone-600 dark:text-slate-300">Continuity ID</span>
                    <input wire:model="continuityId" class="hp-input" />
                </label>
                <button type="submit" class="hp-button-sky w-full">Cargar timeline</button>
            </form>
        </x-slot:aside>

        <div class="grid gap-6 xl:grid-cols-3">
            <x-historia.card title="Turnos" description="{{ count($turns) }} registrados">
                <div class="space-y-3">
                    @forelse ($turns as $turn)
                        <article class="hp-soft">
                            <div class="flex items-center justify-between gap-3">
                                <strong class="text-sm text-stone-900 dark:text-white">Turno {{ $turn['turn_index'] }}</strong>
                                <span class="text-[11px] uppercase tracking-[0.2em] text-stone-500">{{ $turn['mode'] }}</span>
                            </div>
                            <p class="mt-3 whitespace-pre-wrap text-sm leading-7 text-stone-700 dark:text-slate-300">{{ $turn['output_md'] ?: $turn['user_message'] }}</p>
                        </article>
                    @empty
                        <p class="text-sm text-stone-500 dark:text-slate-400">Sin turnos para este contexto.</p>
                    @endforelse
                </div>
            </x-historia.card>

            <x-historia.card title="Commits" description="{{ count($commits) }} snapshots">
                <div class="space-y-3">
                    @forelse ($commits as $commit)
                        <article class="hp-soft">
                            <div class="flex items-center justify-between gap-3">
                                <strong class="text-sm text-stone-900 dark:text-white">Commit {{ $commit['id'] }}</strong>
                                <span class="text-[11px] uppercase tracking-[0.2em] text-stone-500">turn {{ $commit['turn_index'] }}</span>
                            </div>
                            <p class="mt-3 text-sm leading-7 text-stone-700 dark:text-slate-300">{{ $commit['message'] }}</p>
                        </article>
                    @empty
                        <p class="text-sm text-stone-500 dark:text-slate-400">Sin commits para este contexto.</p>
                    @endforelse
                </div>
            </x-historia.card>

            <x-historia.card title="Cambios" description="{{ count($stateChanges) }} state changes">
                <div class="space-y-3">
                    @forelse ($stateChanges as $change)
                        <article class="hp-soft">
                            <div class="flex items-center justify-between gap-3">
                                <strong class="text-sm text-stone-900 dark:text-white">{{ $change['scope_type'] }} / {{ $change['scope_id'] }}</strong>
                                <span class="text-[11px] uppercase tracking-[0.2em] text-stone-500">sev {{ $change['severity'] }}</span>
                            </div>
                            <p class="mt-3 whitespace-pre-wrap text-sm leading-7 text-stone-700 dark:text-slate-300">{{ $change['change'] }}</p>
                        </article>
                    @empty
                        <p class="text-sm text-stone-500 dark:text-slate-400">Sin cambios para este contexto.</p>
                    @endforelse
                </div>
            </x-historia.card>
        </div>
    </x-historia.page-shell>
</x-filament-panels::page>

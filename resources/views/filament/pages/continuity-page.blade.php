<x-filament-panels::page>
    <x-historia.page-shell
        eyebrow="Continuity Ops"
        title="Branching, rewind y operaciones de continuidad."
        description="Usa este panel para generar turnos, abrir ramas y restaurar snapshots sin salir del flujo Filament."
        tone="emerald"
    >
        <x-slot:aside>
            <div class="hp-card hp-card-compact grid gap-4">
                <label class="block text-sm">
                    <span class="mb-1 block text-stone-600 dark:text-slate-300">Scene ID</span>
                    <input wire:model="sceneId" class="hp-input" />
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block text-stone-600 dark:text-slate-300">Continuity ID</span>
                    <input wire:model="continuityId" class="hp-input" />
                </label>
                <label class="block text-sm">
                    <span class="mb-1 block text-stone-600 dark:text-slate-300">Mode</span>
                    <select wire:model="mode" class="hp-select">
                        <option value="write_scene">write_scene</option>
                        <option value="chat">chat</option>
                    </select>
                </label>
                <div class="hp-soft">
                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">Perfil activo</div>
                    <div class="mt-2 font-medium text-stone-900 dark:text-white">{{ auth()->user()?->name ?? 'Usuario MVP' }}</div>
                    <div class="mt-1 text-xs text-stone-500 dark:text-slate-400">
                        #{{ auth()->id() ?? 1 }} · {{ auth()->user()?->email ?? 'mvp@historia.local' }}
                    </div>
                </div>
                <label class="flex items-center gap-3 rounded-2xl border border-stone-200 bg-stone-50 px-3 py-3 text-sm text-stone-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                    <input wire:model="apply" type="checkbox" class="rounded border-stone-300 dark:border-white/10 dark:bg-white/5" />
                    Aplicar
                </label>
            </div>
        </x-slot:aside>

        <div class="grid gap-6 xl:grid-cols-2">
            <x-historia.card title="Generar turno" description="Envía una instrucción sobre la continuidad activa y genera el siguiente paso.">
                <textarea wire:model="userMessage" rows="5" class="hp-textarea" placeholder="Mensaje para la continuidad"></textarea>
                <button wire:click="generateTurn" type="button" class="hp-button-emerald mt-4">Generar</button>
            </x-historia.card>

            <x-historia.card title="Switch de rama" description="Activa otra continuidad para este mismo contexto.">
                <input wire:model="switchContinuityId" class="hp-input mt-4" placeholder="cont_branch" />
                <button wire:click="switchBranch" type="button" class="hp-button-dark mt-4">Cambiar rama activa</button>
            </x-historia.card>

            <x-historia.card title="Crear rama base">
                <div class="grid gap-3">
                    <input wire:model="branchNewContinuityId" class="hp-input" placeholder="nuevo continuity_id" />
                    <input wire:model="branchLabel" class="hp-input" placeholder="label" />
                </div>
                <button wire:click="createBranch" type="button" class="hp-button-dark mt-4">Crear rama</button>
            </x-historia.card>

            <x-historia.card title="Desde turno">
                <div class="grid gap-3">
                    <input wire:model="branchFromTurnNewContinuityId" class="hp-input" placeholder="nuevo continuity_id" />
                    <input wire:model="branchFromTurnTurnIndex" class="hp-input" placeholder="turn_index" />
                    <input wire:model="branchFromTurnLabel" class="hp-input" placeholder="label" />
                </div>
                <button wire:click="createBranchFromTurn" type="button" class="hp-button-dark mt-4">Crear desde turno</button>
            </x-historia.card>

            <x-historia.card title="Desde commit">
                <div class="grid gap-3">
                    <input wire:model="branchFromCommitNewContinuityId" class="hp-input" placeholder="nuevo continuity_id" />
                    <input wire:model="branchFromCommitCommitId" class="hp-input" placeholder="commit_id" />
                    <input wire:model="branchFromCommitLabel" class="hp-input" placeholder="label" />
                </div>
                <button wire:click="createBranchFromCommit" type="button" class="hp-button-dark mt-4">Crear desde commit</button>
            </x-historia.card>

            <x-historia.card title="Checkout y rewind">
                <div class="grid gap-3 md:grid-cols-2">
                    <div class="hp-soft">
                        <input wire:model="checkoutCommitId" class="hp-input" placeholder="commit_id" />
                        <button wire:click="checkoutCommit" type="button" class="hp-button-dark mt-3">Checkout commit</button>
                    </div>
                    <div class="hp-soft">
                        <input wire:model="rewindTurnIndex" class="hp-input" placeholder="turn_index" />
                        <button wire:click="rewindTurn" type="button" class="hp-button-dark mt-3">Rewind turno</button>
                    </div>
                </div>
            </x-historia.card>
        </div>

        <x-historia.card title="Resultado">
            <pre class="hp-code">{{ json_encode($lastResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </x-historia.card>
    </x-historia.page-shell>
</x-filament-panels::page>

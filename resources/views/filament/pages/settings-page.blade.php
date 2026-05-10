<x-filament-panels::page>
    {{-- Model catalog for the Alpine combobox. Emitted once, updated reactively via Livewire event. --}}
    <script>
        window.HP_MODELS = @js(array_values($openRouterModelLookup));

        // Re-populate HP_MODELS whenever the catalog is refreshed (Livewire dispatches this event).
        window.addEventListener('hp-models-updated', (e) => {
            window.HP_MODELS = e.detail.models ?? [];
        });

        function hpModelPicker(wireKey) {
            return {
                wireKey,
                query: '',
                open: false,
                filtered: [],

                init() {
                    this.query = this.$wire.get(this.wireKey) || '';
                    // Keep query in sync when Livewire pushes a new value (e.g. after load)
                    this.$wire.$watch(this.wireKey, (val) => {
                        if ((val || '') !== this.query) this.query = val || '';
                    });
                },

                filter() {
                    const q = this.query.toLowerCase().trim();
                    if (!q) { this.filtered = []; this.open = false; return; }
                    this.filtered = (window.HP_MODELS || [])
                        .filter(m =>
                            (m.id   || '').toLowerCase().includes(q) ||
                            (m.name || '').toLowerCase().includes(q)
                        )
                        .slice(0, 16);
                    this.open = this.filtered.length > 0;
                },

                select(model) {
                    const id = typeof model === 'string' ? model : (model.id || model);
                    this.query = id;
                    this.$wire.set(this.wireKey, id);
                    // Si el modelo tiene provider_slug y el picker es de agentModels, propaga el proveedor
                    if (typeof model === 'object' && model.provider_slug !== undefined && this.wireKey.startsWith('agentModels.')) {
                        const providerKey = this.wireKey.replace('agentModels.', 'agentProviders.');
                        this.$wire.set(providerKey, model.provider_slug || '');
                    }
                    this.open = false;
                    this.filtered = [];
                },

                selectFirst() {
                    if (this.filtered.length > 0) {
                        this.select(this.filtered[0]);
                    } else {
                        // User typed a raw model ID without picking from the list — commit it.
                        const val = this.query.trim();
                        if (val) this.$wire.set(this.wireKey, val);
                        this.open = false;
                    }
                },

                commit() {
                    // On blur: commit whatever is in the input so manual paste works too.
                    const val = this.query.trim();
                    if (val) this.$wire.set(this.wireKey, val);
                    setTimeout(() => { this.open = false; }, 150);
                }
            };
        }
    </script>

    <x-historia.page-shell
        eyebrow="Configuración"
        title="Contexto, modelos y agentes del runtime."
        description="Aquí ajustas el contexto compartido del panel y toda la asignación de modelos que usa el sistema para correr tus agentes."
        tone="fuchsia"
    >
        <x-slot:aside>
            <x-historia.stat label="Writer" :value="$this->runtimeConfig['writer_model']" />
        </x-slot:aside>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_420px]">

            {{-- ── Presets globales ─────────────────────────────────────────────── --}}
            <x-historia.card title="Presets globales" description="El preset activo es la configuración base del sistema. Selecciona uno para cargarlo, actualízalo con el formulario actual o crea uno nuevo." class="xl:col-span-2">

                {{-- Selector de preset --}}
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-48">
                        <label class="block text-sm">
                            <span class="mb-1 block text-stone-600 dark:text-slate-300">Preset</span>
                            <select wire:model="selectedPresetId" class="hp-select">
                                <option value="">— Selecciona un preset —</option>
                                @foreach ($globalPresets as $preset)
                                    <option value="{{ $preset['id'] }}">
                                        {{ $preset['active'] ? '★ ' : '' }}{{ $preset['name'] }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    </div>
                    <button wire:click="loadSelectedPreset" type="button" class="hp-button-secondary">Cargar</button>
                    <button wire:click="activateSelectedPreset" type="button" class="hp-button-fuchsia">Activar</button>
                    <button wire:click="updateSelectedPreset" type="button" class="hp-button-secondary">Actualizar</button>
                    <button wire:click="deleteSelectedPreset" type="button" class="hp-button-secondary text-danger-600 dark:text-danger-400">Eliminar</button>
                </div>

                {{-- Crear nuevo preset --}}
                <div class="mt-4 flex flex-wrap items-end gap-3 border-t border-stone-200 pt-4 dark:border-white/10">
                    <div class="flex-1 min-w-48">
                        <label class="block text-sm">
                            <span class="mb-1 block text-stone-600 dark:text-slate-300">Nombre del nuevo preset</span>
                            <input wire:model="presetName" class="hp-input" placeholder="ej. AMD Production, OpenRouter Dev…" />
                        </label>
                    </div>
                    <button wire:click="saveAsPreset" type="button" class="hp-button-fuchsia">Guardar como nuevo</button>
                </div>
            </x-historia.card>

            {{-- ── Ámbito de overrides (player/vault/scene) ─────────────────────── --}}
            <x-historia.card title="Overrides por ámbito" description="Overrides sobre el preset activo para un player, vault o scene específico. La jerarquía es: Preset activo → Player → Vault → Scene." class="xl:col-span-2">
                <div class="flex flex-wrap gap-3">
                    @foreach (['global' => 'Global', 'player' => 'Player', 'vault' => 'Vault', 'scene' => 'Scene'] as $scope => $label)
                        <button
                            wire:click="$set('configScope', '{{ $scope }}')"
                            type="button"
                            class="{{ $configScope === $scope ? 'hp-button-fuchsia' : 'hp-button-secondary' }}"
                        >{{ $label }}</button>
                    @endforeach
                </div>

                @if ($configScope === 'global')
                    <div class="mt-3 text-sm text-stone-600 dark:text-slate-300">
                        Sobreescribe el preset activo. Se aplica como configuración base del sistema.
                    </div>
                @elseif ($configScope === 'vault')
                    <label class="mt-4 block text-sm">
                        <span class="mb-1 block text-stone-600 dark:text-slate-300">Vault ID</span>
                        <input wire:model="vaultId" class="hp-input" placeholder="ej. vault_abc123" />
                        <div class="mt-1 text-xs text-stone-500 dark:text-slate-400">Se precarga desde el contexto de sesión. Puedes editarlo aquí.</div>
                    </label>
                @elseif ($configScope === 'scene')
                    <div class="mt-3 text-sm text-stone-600 dark:text-slate-300">
                        Se usará el <span class="font-medium text-stone-900 dark:text-white">Scene ID</span> del contexto actual:
                        <code class="ml-1 rounded bg-stone-100 px-1 py-0.5 text-xs dark:bg-white/10">{{ $sceneId ?: '(vacío)' }}</code>
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap gap-3">
                    <button wire:click="loadConfigForScope" type="button" class="hp-button-secondary">Cargar {{ $configScope }}</button>
                    <button wire:click="saveConfigForScope" type="button" class="hp-button-fuchsia">Guardar {{ $configScope }}</button>
                </div>
            </x-historia.card>

            <x-historia.card title="Contexto compartido" description="Estos valores quedan en sesión para precargar las demás páginas del panel.">
                <div class="grid gap-4 md:grid-cols-2">
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
                </div>

                <label class="mt-4 flex items-center gap-3 rounded-2xl border border-stone-200 bg-stone-50 px-3 py-3 text-sm text-stone-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                    <input wire:model="apply" type="checkbox" class="rounded border-stone-300 dark:border-white/10 dark:bg-white/5" />
                    Aplicar cambios por defecto
                </label>

                <button wire:click="saveContext" type="button" class="hp-button-fuchsia mt-6">Guardar contexto</button>

                <div class="mt-6 hp-soft">
                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">Perfil actual</div>
                    <div class="mt-3 font-medium text-stone-900 dark:text-white">
                        {{ $this->currentUser?->name ?? 'Usuario MVP' }}
                    </div>
                    <div class="mt-1 text-sm text-stone-500 dark:text-slate-400">
                        #{{ $this->currentUser?->id ?? $userId }} · {{ $this->currentUser?->email ?? 'mvp@historia.local' }}
                    </div>
                </div>
            </x-historia.card>

            <x-historia.card title="Configuración general" description="Ajusta el timeout y parámetros globales. Los modelos se asignan por agente — busca cualquier ruta configurada directamente en el picker.">
                <div class="grid gap-4">
                    <label class="block text-sm">
                        <span class="mb-1 block text-stone-600 dark:text-slate-300">Timeout ms</span>
                        <input wire:model="timeoutMs" class="hp-input" placeholder="120000" />
                    </label>
                </div>

                <div class="mt-6 flex flex-wrap gap-3">
                    <button wire:click="refreshOpenRouterCatalog" type="button" class="hp-button-secondary">Actualizar catálogo</button>
                    <button wire:click="loadConfigForScope" type="button" class="hp-button-secondary">Cargar {{ $configScope }}</button>
                    <button wire:click="saveConfigForScope" type="button" class="hp-button-fuchsia">Guardar {{ $configScope }}</button>
                </div>
            </x-historia.card>

            @php
                $simpleWriter = $this->simpleModeAgent;
                $simpleWriterModelId = $simpleWriter ? ($agentModels[$simpleWriter['key']] ?? $simpleWriter['model']) : null;
                $simpleWriterModel = $simpleWriterModelId ? ($openRouterModelLookup[$simpleWriterModelId] ?? null) : null;
                $simpleWriterUsesAnthropicCache = is_string($simpleWriterModelId) && str_starts_with($simpleWriterModelId, 'anthropic/');
            @endphp

            @if ($simpleWriter)
                <x-historia.card title="Modo simple" description="Este writer es el que usa hoy la generación simple de escena. Si eliges un modelo `anthropic/...` vía OpenRouter, el runtime activa cache especial para el prompt." class="xl:col-span-2">
                    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-fuchsia-200 bg-fuchsia-50 px-4 py-3 text-sm dark:border-fuchsia-400/20 dark:bg-fuchsia-400/10">
                        <div class="text-stone-700 dark:text-fuchsia-100">
                            Los cambios de modelo y parámetros no se guardan automáticamente.
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <button wire:click="loadConfigForScope" type="button" class="hp-button-secondary">Descartar cambios</button>
                            <button wire:click="saveConfigForScope" type="button" class="hp-button-fuchsia">Guardar cambios</button>
                        </div>
                    </div>

                    <div class="hp-soft">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="font-medium text-stone-900 dark:text-white">{{ $simpleWriter['label'] }}</div>
                                <div class="mt-1 text-xs uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">{{ $simpleWriter['key'] }} · generación simple</div>
                            </div>
                            <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] {{ $simpleWriterUsesAnthropicCache ? 'bg-amber-100 text-amber-700 dark:bg-amber-400/10 dark:text-amber-200' : 'bg-stone-200 text-stone-600 dark:bg-white/10 dark:text-slate-300' }}">
                                {{ $simpleWriterUsesAnthropicCache ? 'cache activa' : 'sin cache especial' }}
                            </span>
                        </div>
                        <label class="mt-4 block text-sm">
                            <span class="mb-1 block text-stone-600 dark:text-slate-300">Modelo del writer simple</span>
                            @include('filament.partials.model-picker', ['wireKey' => 'agentModels.' . $simpleWriter['key']])
                        </label>
                        <div class="mt-3 text-xs leading-6 text-stone-500 dark:text-slate-400">
                            <div><span class="font-semibold">Costo:</span> {{ $simpleWriterModel['price_label'] ?? 'Costo no disponible' }}</div>
                            @if (filled($simpleWriterModel['context_length'] ?? null))
                                <div><span class="font-semibold">Contexto:</span> {{ number_format((int) $simpleWriterModel['context_length']) }} tokens</div>
                            @endif
                            <div><span class="font-semibold">Cache:</span> {{ $simpleWriterUsesAnthropicCache ? 'El writer enviará cache_control al gateway.' : 'No se enviará cache_control.' }}</div>
                            <div><span class="font-semibold">Session ID:</span> automático por escena, continuidad y usuario.</div>
                        </div>

                        <div class="mt-5 grid gap-3 md:grid-cols-2">
                            <label class="block text-sm">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">Perfil de estilo</span>
                                <select wire:model="writerStyleProfile" class="hp-select">
                                    <option value="cinematico">Cinemático</option>
                                    <option value="sobrio">Sobrio</option>
                                    <option value="intimo">Íntimo</option>
                                    <option value="sensorial">Sensorial</option>
                                    <option value="rapido">Rápido</option>
                                    <option value="oscuro">Oscuro</option>
                                    <option value="romantico">Romántico</option>
                                </select>
                            </label>
                            <label class="block text-sm">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">Longitud de respuesta</span>
                                <select wire:model="writerResponseLength" class="hp-select">
                                    <option value="corto">Corto</option>
                                    <option value="medio">Medio</option>
                                    <option value="largo">Largo</option>
                                </select>
                            </label>
                            <label class="block text-sm">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">Temperature</span>
                                <input wire:model="writerTemperature" class="hp-input" />
                            </label>
                            <label class="block text-sm">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">Max output tokens</span>
                                <input wire:model="writerMaxOutputTokens" class="hp-input" />
                            </label>
                            <label class="block text-sm">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">Top P</span>
                                <input wire:model="writerTopP" class="hp-input" />
                            </label>
                            <label class="block text-sm">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">Presence penalty</span>
                                <input wire:model="writerPresencePenalty" class="hp-input" />
                            </label>
                            <label class="block text-sm md:col-span-2">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">Frequency penalty</span>
                                <input wire:model="writerFrequencyPenalty" class="hp-input" />
                            </label>
                            <label class="block text-sm md:col-span-2">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">Notas de estilo</span>
                                <textarea wire:model="writerStyleNotes" rows="4" class="hp-input" placeholder="Ej. prose con más tensión, subtexto romántico, menos adjetivos, más ambiente húmedo."></textarea>
                            </label>
                            <label class="block text-sm">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">QA en simple</span>
                                <select wire:model="qaPolicySimple" class="hp-select">
                                    <option value="adaptive">Adaptativo</option>
                                    <option value="auto">Siempre automático</option>
                                    <option value="manual">Manual / opcional</option>
                                    <option value="disabled">Desactivado</option>
                                </select>
                            </label>
                            <div class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3 text-xs leading-6 text-stone-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                Si el modelo de QA es gratis, `adaptativo` lo deja en automático. Si es de pago, lo deja manual para ahorrar costo.
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        <button wire:click="loadConfigForScope" type="button" class="hp-button-secondary">Descartar cambios</button>
                        <button wire:click="saveConfigForScope" type="button" class="hp-button-fuchsia">Guardar cambios</button>
                    </div>
                </x-historia.card>
            @endif

            @php
                $sectionMeta = [
                    'Ingesta'   => ['icon' => '📥', 'description' => 'Procesan datos externos y los convierten a representaciones internas.'],
                    'Memoria'   => ['icon' => '🧠', 'description' => 'Recuperan contexto, lore y perfiles desde Qdrant.'],
                    'Narrativa' => ['icon' => '✍️', 'description' => 'Generan y validan el texto de la partida.'],
                    'General'   => ['icon' => '•',  'description' => 'Otros agentes.'],
                ];
            @endphp

            @foreach ($this->agentsBySection as $section => $sectionAgents)
                <x-historia.card
                    :title="($sectionMeta[$section]['icon'] ?? '•') . ' ' . $section"
                    :description="$sectionMeta[$section]['description'] ?? ''"
                    class="xl:col-span-2"
                >
                    <div class="grid gap-3 md:grid-cols-2">
                        @foreach ($sectionAgents as $agent)
                            <div class="hp-soft {{ $agent['enabled'] ? '' : 'opacity-60' }}">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <div class="font-medium text-stone-900 dark:text-white">{{ $agent['label'] }}</div>
                                        <div class="mt-1 text-xs uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">{{ $agent['key'] }}</div>
                                    </div>
                                    <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] {{ $agent['enabled'] ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-200' : 'bg-stone-200 text-stone-600 dark:bg-white/10 dark:text-slate-300' }}">
                                        {{ $agent['enabled'] ? 'activo' : 'off' }}
                                    </span>
                                </div>
                                <label class="mt-4 block text-sm">
                                    <span class="mb-1 block text-stone-600 dark:text-slate-300">Modelo</span>
                                    @include('filament.partials.model-picker', ['wireKey' => 'agentModels.' . $agent['key']])
                                </label>
                                @php
                                    $selectedModelId = $agentModels[$agent['key']] ?? $agent['model'];
                                    $selectedModel = $openRouterModelLookup[$selectedModelId] ?? null;
                                @endphp
                                <div class="mt-2 text-xs leading-5 text-stone-500 dark:text-slate-400">
                                    <span class="font-semibold">Costo:</span> {{ $selectedModel['price_label'] ?? 'n/d' }}
                                    @if (filled($selectedModel['context_length'] ?? null))
                                        · <span class="font-semibold">Ctx:</span> {{ number_format((int) $selectedModel['context_length']) }}
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    @if ($section === 'Narrativa')
                        <div class="mt-5 grid gap-3 md:grid-cols-2">
                            <label class="block text-sm">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">QA en complex</span>
                                <select wire:model="qaPolicyComplex" class="hp-select">
                                    <option value="adaptive">Adaptativo</option>
                                    <option value="auto">Siempre automático</option>
                                    <option value="manual">Manual / opcional</option>
                                    <option value="disabled">Desactivado</option>
                                </select>
                            </label>
                            <div class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3 text-xs leading-6 text-stone-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                Aplica al modo complejo. <em>Adaptativo</em> usa QA automático solo si el modelo no tiene costo.
                            </div>
                        </div>
                    @endif

                    <div class="mt-6 flex flex-wrap justify-end gap-3">
                        <button wire:click="loadConfigForScope" type="button" class="hp-button-secondary">Descartar cambios</button>
                        <button wire:click="saveConfigForScope" type="button" class="hp-button-fuchsia">Guardar cambios</button>
                    </div>
                </x-historia.card>
            @endforeach

            <x-historia.card title="Modelos disponibles" description="Catálogo de OpenRouter con costo estimado por millón de tokens para elegir los modelos de tu perfil." class="xl:col-span-2">
                <div class="grid gap-3 md:grid-cols-2">
                    @foreach (array_slice($openRouterModelLookup, 0, 24, true) as $model)
                        <div class="hp-soft">
                            <div class="font-medium text-stone-900 dark:text-white">{{ $model['name'] }}</div>
                            <div class="mt-1 text-xs text-stone-500 dark:text-slate-400">{{ $model['id'] }}</div>
                            <div class="mt-3 text-sm text-stone-700 dark:text-slate-300">{{ $model['price_label'] }}</div>
                        </div>
                    @endforeach
                </div>
            </x-historia.card>

            <x-historia.card title="Runtime efectivo" description="Resolución actual para la sesión y el scope seleccionado." class="xl:col-span-2">
                <dl class="grid gap-3 md:grid-cols-2 xl:grid-cols-3 text-sm">
                    @foreach (['gatekeeper' => 'Gatekeeper', 'embedding' => 'Embedding', 'librarian' => 'Librarian', 'writer' => 'Writer', 'critic' => 'Critic'] as $agentKey => $agentLabel)
                        <div class="hp-soft">
                            <dt class="text-stone-500 dark:text-slate-400">{{ $agentLabel }}</dt>
                            <dd class="mt-2 break-all font-medium text-stone-900 dark:text-white">
                                {{ $agentModels[$agentKey] ?? '—' }}
                            </dd>
                        </div>
                    @endforeach
                    <div class="hp-soft">
                        <dt class="text-stone-500 dark:text-slate-400">Timeout</dt>
                        <dd class="mt-2 font-medium text-stone-900 dark:text-white">{{ $this->runtimeConfig['timeout_ms'] }} ms</dd>
                    </div>
                    <div class="hp-soft">
                        <dt class="text-stone-500 dark:text-slate-400">Writer style</dt>
                        <dd class="mt-2 font-medium text-stone-900 dark:text-white">{{ $this->runtimeConfig['writer_style_profile'] }}</dd>
                    </div>
                    <div class="hp-soft">
                        <dt class="text-stone-500 dark:text-slate-400">Critic mode</dt>
                        <dd class="mt-2 font-medium text-stone-900 dark:text-white">{{ $this->runtimeConfig['critic_mode'] }}</dd>
                    </div>
                    <div class="hp-soft">
                        <dt class="text-stone-500 dark:text-slate-400">OPENROUTER_API_KEY</dt>
                        <dd class="mt-2 font-medium {{ $this->runtimeConfig['openrouter_key'] ? 'text-success-600' : 'text-danger-600' }}">
                            {{ $this->runtimeConfig['openrouter_key'] ? 'configurada' : 'faltante' }}
                        </dd>
                    </div>
                    <div class="hp-soft">
                        <dt class="text-stone-500 dark:text-slate-400">Cache writer</dt>
                        <dd class="mt-2 font-medium {{ str_starts_with($this->runtimeConfig['writer_model'], 'anthropic/') ? 'text-amber-600 dark:text-amber-200' : 'text-stone-900 dark:text-white' }}">
                            {{ str_starts_with($this->runtimeConfig['writer_model'], 'anthropic/') ? 'Activa (anthropic/ via OpenRouter)' : 'Inactiva' }}
                        </dd>
                    </div>
                </dl>
            </x-historia.card>

            <div class="xl:col-span-2">
                <div class="sticky bottom-4 z-10 flex flex-wrap items-center justify-between gap-3 rounded-[1.75rem] border border-stone-200 bg-white/95 px-5 py-4 shadow-lg backdrop-blur dark:border-white/10 dark:bg-slate-950/90">
                    <div class="text-sm text-stone-600 dark:text-slate-300">
                        Cambiaste modelos o parámetros del runtime.
                        <span class="font-medium text-stone-900 dark:text-white">Guarda para aplicarlos a tu perfil.</span>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <button wire:click="loadConfigForScope" type="button" class="hp-button-secondary">Recargar {{ $configScope }}</button>
                        <button wire:click="saveConfigForScope" type="button" class="hp-button-fuchsia">Guardar cambios</button>
                    </div>
                </div>
            </div>
        </div>
    </x-historia.page-shell>
</x-filament-panels::page>

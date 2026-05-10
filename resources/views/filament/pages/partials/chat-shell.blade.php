@php($experience = $this->getChatConfig()['experience'])

<div x-data="window.filamentHistoriaChat(window.filamentHistoriaChatConfig)" x-init="init()" class="space-y-6">
    <x-historia.page-shell
        :eyebrow="$experience['eyebrow']"
        :title="$experience['title']"
        :description="$experience['description']"
        :tone="$experience['tone']"
    >
        <x-slot:aside>
            <x-historia.stat label="API" value="sin cargar" x-text="health.status || 'sin cargar'" />
            <x-historia.stat label="Chat" value="listo" x-text="sending ? 'enviando' : 'listo'" />
            <x-historia.stat label="Turnos" value="0" x-text="timeline.turns.length" />
        </x-slot:aside>

        <div class="space-y-6">
            <section class="hp-card overflow-hidden !p-0">
                <div class="border-b border-stone-200 px-6 py-5 dark:border-white/10">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-xl font-semibold text-stone-950 dark:text-white" x-text="experience.chat_title"></h2>
                            <p class="mt-1 text-sm text-stone-600 dark:text-slate-300" x-text="experience.chat_description"></p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span class="rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em]" :class="deliveryBadgeTone" x-text="deliveryBadgeLabel"></span>
                                <span class="rounded-full border border-stone-200 bg-stone-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400" x-text="experience.runtime_badge"></span>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span class="rounded-full border border-stone-200 bg-stone-50 px-3 py-1 text-[11px] text-stone-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                    Vault: <strong x-text="vaultId || 'sin vault'"></strong>
                                </span>
                                <span class="rounded-full border border-stone-200 bg-stone-50 px-3 py-1 text-[11px] text-stone-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                    Escena: <strong x-text="sceneId || 'sin escena'"></strong>
                                </span>
                                <template x-if="experience.show_continuity_id">
                                    <span class="rounded-full border border-stone-200 bg-stone-50 px-3 py-1 text-[11px] text-stone-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                        Continuidad: <strong x-text="continuityId || 'sin continuidad'"></strong>
                                    </span>
                                </template>
                                <template x-for="quest in (questStatuses ?? [])" :key="quest.quest_id">
                                    <span class="rounded-full border border-stone-200 bg-stone-50 px-3 py-1 text-[11px] text-stone-600 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                        Quest: <strong x-text="quest.title"></strong>
                                        (<span x-text="quest.status" :class="quest.status === 'active' ? 'text-emerald-600' : 'text-stone-500'"></span>) -
                                        Step: <strong x-text="quest.current_step?.description || quest.current_stage_number"></strong>
                                    </span>
                                </template>
                            </div>
                        </div>
                        <div class="flex flex-col items-end gap-2">
                            <div class="flex gap-2">
                                <button type="button" @click="showContextModal = true" class="hp-button-secondary !px-3 !py-2 text-xs" :disabled="!currentContextData">
                                    Ver Contexto
                                </button>
                                <button type="button" @click="loadTimeline()" class="hp-button-secondary !px-3 !py-2 text-xs">
                                    Recargar timeline
                                </button>
                                <button type="button" @click="controlPanelOpen = !controlPanelOpen" class="hp-button-dark !px-3 !py-2 text-xs">
                                    <span x-text="controlPanelOpen ? 'Ocultar menus' : 'Mostrar menus'"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <template x-if="error">
                    <div class="mx-6 mt-6 rounded-2xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200" x-text="error"></div>
                </template>

                <template x-if="!hasChatContext">
                    <div class="mx-6 mt-6 rounded-[1.75rem] border border-amber-300 bg-amber-50/90 p-5 dark:border-amber-400/30 dark:bg-amber-400/10">
                        <div class="flex flex-col gap-4">
                            <div>
                                <h3 class="text-lg font-semibold text-stone-950 dark:text-white">Primero prepara la escena</h3>
                                <p class="mt-1 text-sm leading-6 text-stone-600 dark:text-slate-300">
                                    Antes de continuar el roleplay necesitas seleccionar un vault y elegir una escena existente o crear una nueva.
                                </p>
                            </div>

                            <div class="grid gap-4 lg:grid-cols-2">
                                <label class="block text-sm">
                                    <span class="mb-1 block text-stone-600 dark:text-slate-300">Vault</span>
                                    <select x-model="vaultId" @change="onVaultChanged()" class="hp-select">
                                        <option value="">Selecciona un vault</option>
                                        <template x-for="vault in (library.vaults ?? [])" :key="vault.id">
                                            <option :value="vault.id" x-text="vault.name"></option>
                                        </template>
                                    </select>
                                </label>

                                <label class="block text-sm">
                                    <span class="mb-1 block text-stone-600 dark:text-slate-300">Escena existente</span>
                                    <select x-model="sceneId" @change="loadTimeline()" class="hp-select">
                                        <option value="">Selecciona una escena</option>
                                        <template x-for="scene in filteredScenes" :key="scene.id">
                                            <option :value="scene.id" x-text="scene.title"></option>
                                        </template>
                                    </select>
                                </label>
                            </div>

                            <div class="rounded-2xl border border-stone-200 bg-white/70 p-4 dark:border-white/10 dark:bg-white/5">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">Crear escena nueva</div>
                                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                    <label class="block text-sm">
                                        <span class="mb-1 block text-stone-600 dark:text-slate-300">Scene ID</span>
                                        <input x-model="newSceneId" class="hp-input" placeholder="scene_demo" />
                                    </label>
                                    <label class="block text-sm">
                                        <span class="mb-1 block text-stone-600 dark:text-slate-300">Título</span>
                                        <input x-model="newSceneTitle" class="hp-input" placeholder="Llegada al mercado" />
                                    </label>
                                    <label class="block text-sm">
                                        <span class="mb-1 block text-stone-600 dark:text-slate-300">Location</span>
                                        <select x-model="newSceneLocationId" class="hp-select">
                                            <option value="">Selecciona una location</option>
                                            <template x-for="location in filteredLocations" :key="location.id">
                                                <option :value="location.id" x-text="location.name"></option>
                                            </template>
                                        </select>
                                    </label>
                                    <label class="block text-sm">
                                        <span class="mb-1 block text-stone-600 dark:text-slate-300">Quest existente</span>
                                        <select x-model="newSceneQuestId" class="hp-select">
                                            <option value="">Generar desde prompt</option>
                                            <template x-for="quest in filteredQuests" :key="quest.id">
                                                <option :value="quest.id" x-text="quest.title"></option>
                                            </template>
                                        </select>
                                    </label>
                                    <label class="block text-sm lg:col-span-2">
                                        <span class="mb-1 block text-stone-600 dark:text-slate-300">Quest prompt</span>
                                        <textarea x-model="newSceneQuestPrompt" rows="3" class="hp-textarea" placeholder="Ej. El protagonista debe escapar del refugio sin alertar a la facción."></textarea>
                                        <span class="mt-1 block text-xs text-stone-500 dark:text-slate-400">Usa una quest existente o escribe un prompt para generar una base de varios steps.</span>
                                    </label>
                                    <div class="flex items-end">
                                        <button type="button" @click="createSceneRecord()" :disabled="creatingScene || vaultId.trim() === ''" class="hp-button-amber disabled:opacity-50">
                                            <span x-text="creatingScene ? 'Creando...' : 'Crear escena'"></span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <template x-if="sceneId.trim() !== '' && experience.show_character_import">
                                <div class="rounded-2xl border border-stone-200 bg-white/70 p-4 dark:border-white/10 dark:bg-white/5">
                                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">Agregar personajes a la escena</div>
                                    <div class="mt-4 space-y-3">
                                        <template x-if="filteredCharacters.length === 0">
                                            <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
                                                No hay personajes disponibles para este vault.
                                            </div>
                                        </template>

                                        <template x-for="character in filteredCharacters.slice(0, 6)" :key="`setup-import-${character.id}`">
                                            <div class="flex items-center justify-between gap-3 rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                                                <div>
                                                    <div class="font-medium text-stone-900 dark:text-white" x-text="character.name"></div>
                                                    <div class="mt-1 text-xs text-stone-500 dark:text-slate-400" x-text="character.id"></div>
                                                </div>
                                                <button type="button" class="hp-button-secondary !px-3 !py-2 text-xs" @click="importCharacter(character.id)">
                                                    Importar
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Panel de motor de turnos VTT --}}
                <template x-if="$store.chatEngine && $store.chatEngine.isActive">
                    <div class="mx-6 mt-4 rounded-2xl border px-4 py-3 flex items-center justify-between gap-3 text-sm"
                         :class="$store.chatEngine.isMyTurn
                             ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-400/30 dark:bg-emerald-400/10'
                             : 'border-amber-200 bg-amber-50/70 dark:border-amber-400/20 dark:bg-amber-400/5'">
                        <div class="flex items-center gap-2">
                            <span class="text-lg" x-text="$store.chatEngine.isMyTurn ? '✦' : '⏳'"></span>
                            <span class="font-medium" :class="$store.chatEngine.isMyTurn ? 'text-emerald-800 dark:text-emerald-300' : 'text-amber-800 dark:text-amber-300'"
                                  x-text="$store.chatEngine.turnLabel"></span>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-stone-500 dark:text-slate-400">
                            <span>Ronda <strong x-text="$store.chatEngine.roundNumber"></strong></span>
                            <template x-if="$store.chatEngine.characters.length > 0">
                                <span x-text="`${$store.chatEngine.characters.length} personajes`"></span>
                            </template>
                        </div>
                    </div>
                </template>

                <div x-ref="chatScroll" class="max-h-[58vh] space-y-4 overflow-y-auto bg-[linear-gradient(to_bottom,_rgba(245,245,244,0.45),_rgba(255,255,255,0))] px-6 py-6 dark:bg-[linear-gradient(to_bottom,_rgba(255,255,255,0.03),_rgba(255,255,255,0))]">
                    <template x-if="messages.length === 0 && hasChatContext">
                        <div class="rounded-[1.75rem] border border-dashed border-stone-300 bg-stone-50/70 px-6 py-12 text-center text-sm leading-7 text-stone-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
                            Todavía no hay mensajes. Empieza con una instrucción narrativa y el panel irá construyendo el hilo.
                        </div>
                    </template>

                    <template x-for="message in messages" :key="message.id">
                        <article class="max-w-4xl rounded-[1.6rem] border px-4 py-4 shadow-sm" :class="message.role === 'user' ? 'ml-auto border-amber-300 bg-amber-50 text-stone-900 dark:border-amber-400/30 dark:bg-amber-400/10 dark:text-white' : 'mr-auto border-stone-200 bg-white text-stone-900 dark:border-white/10 dark:bg-white/5 dark:text-white'">
                            <div class="mb-2 flex items-center justify-between gap-4 text-[11px] font-semibold uppercase tracking-[0.22em] text-stone-500 dark:text-slate-400">
                                <span x-text="message.role === 'user' ? 'Usuario' : 'Asistente'"></span>
                                <span x-text="message.meta"></span>
                            </div>
                            <div class="whitespace-pre-wrap text-sm leading-7" x-text="message.content"></div>

                            <template x-if="message.questDirective">
                                <div class="mt-3 rounded-xl border border-blue-200 bg-blue-50/50 p-3 text-xs text-blue-800 dark:border-blue-400/30 dark:bg-blue-400/10 dark:text-blue-200">
                                    <div class="font-semibold uppercase tracking-wider mb-1">Directiva de Quest</div>
                                    <div x-text="message.questDirective.directive_for_writer"></div>
                                    <template x-if="message.questUpdateSummary && message.questUpdateSummary.applied">
                                        <div class="mt-1 font-bold text-emerald-600 dark:text-emerald-400">
                                            Progreso: <span x-text="message.questUpdateSummary.status"></span> (Etapa <span x-text="message.questUpdateSummary.currentStageNumber"></span>)
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <template x-if="message.eventTriggers && message.eventTriggers.length > 0">
                                <div class="mt-3 rounded-xl border border-fuchsia-200 bg-fuchsia-50/50 p-3 text-xs text-fuchsia-800 dark:border-fuchsia-400/30 dark:bg-fuchsia-400/10 dark:text-fuchsia-200">
                                    <div class="font-semibold uppercase tracking-wider mb-1">Eventos Disparados</div>
                                    <ul class="list-disc pl-4 space-y-1">
                                        <template x-for="event in (message.eventTriggers ?? [])" :key="event.id">
                                            <li x-text="event.title || event.event_id"></li>
                                        </template>
                                    </ul>
                                </div>
                            </template>

                            <template x-if="message.characterStatusUpdateSummary && message.characterStatusUpdateSummary.appliedCount > 0">
                                <div class="mt-3 rounded-xl border border-stone-200 bg-stone-50/50 p-3 text-xs text-stone-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                    <div class="font-semibold uppercase tracking-wider mb-1">Estado de Personajes</div>
                                    <div class="flex flex-wrap gap-2 mt-1">
                                        <span class="rounded-full bg-stone-200/50 px-2 py-0.5 text-[10px]" x-text="`Cambios aplicados: ${message.characterStatusUpdateSummary.appliedCount}`"></span>
                                        <template x-if="message.characterStatusUpdateSummary.warnings && message.characterStatusUpdateSummary.warnings.length > 0">
                                            <span class="rounded-full bg-amber-100 text-amber-700 px-2 py-0.5 text-[10px]" x-text="`${message.characterStatusUpdateSummary.warnings.length} advertencias`"></span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </article>
                    </template>
                </div>

                <div class="border-t border-stone-200 bg-stone-50/70 px-6 py-5 dark:border-white/10 dark:bg-white/5">
                    <label class="block text-sm">
                        <span class="mb-2 block font-medium text-stone-700 dark:text-slate-300">Mensaje</span>
                        <textarea
                            x-model="draft"
                            @keydown.meta.enter.prevent="!($store.chatEngine?.inputBlocked) && sendChat()"
                            @keydown.ctrl.enter.prevent="!($store.chatEngine?.inputBlocked) && sendChat()"
                            rows="5"
                            class="hp-textarea"
                            :class="$store.chatEngine?.inputBlocked ? 'opacity-50 cursor-not-allowed' : ''"
                            :disabled="$store.chatEngine?.inputBlocked"
                            :placeholder="$store.chatEngine?.inputBlocked
                                ? 'Esperando tu turno — ' + ($store.chatEngine?.turnLabel || 'turno de otro personaje')
                                : 'Describe el siguiente paso de la escena...'"
                        ></textarea>
                    </label>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <span class="text-xs uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">
                            <template x-if="$store.chatEngine?.inputBlocked">
                                <span class="text-amber-600 dark:text-amber-400" x-text="$store.chatEngine.turnLabel"></span>
                            </template>
                            <template x-if="!($store.chatEngine?.inputBlocked)">
                                <span>Cmd/Ctrl + Enter para enviar</span>
                            </template>
                        </span>
                        <div class="flex gap-3">
                            <template x-if="experience.show_scene_create">
                                <button type="button" @click="createScene()"
                                        :disabled="sending || !canSend || $store.chatEngine?.inputBlocked"
                                        class="hp-button-secondary disabled:opacity-50">
                                    Escena simple
                                </button>
                            </template>
                            <button type="button" @click="sendChat()"
                                    :disabled="sending || !canSend || $store.chatEngine?.inputBlocked"
                                    class="hp-button-amber disabled:opacity-50">
                                <span x-text="sending ? 'Enviando...' : experience.send_label"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <div
                x-cloak
                x-show="controlPanelOpen"
                x-transition.opacity
                class="fixed inset-0 z-40 bg-stone-950/45 backdrop-blur-[2px] xl:hidden"
                @click="controlPanelOpen = false"
            ></div>

            <aside
                x-cloak
                x-show="controlPanelOpen"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="translate-y-3 opacity-0 xl:translate-x-4"
                x-transition:enter-end="translate-y-0 opacity-100 xl:translate-x-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="translate-y-0 opacity-100"
                x-transition:leave-end="translate-y-3 opacity-0 xl:translate-x-4"
                class="fixed inset-x-3 top-24 bottom-3 z-50 overflow-y-auto rounded-[2rem] border border-stone-200 bg-white/95 p-4 shadow-2xl backdrop-blur dark:border-white/10 dark:bg-[#171411]/95 xl:absolute xl:right-0 xl:top-0 xl:bottom-auto xl:w-[360px]"
            >
                <div class="space-y-4 xl:space-y-6">
                    <x-historia.card title="Configurar chat" description="Todo el contexto y las opciones viven aquí para que el chat siga siendo el centro.">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex flex-wrap gap-2">
                                <a :href="links.simple" class="hp-button-secondary !px-3 !py-2 text-xs" :class="experience.key === 'simple' ? 'opacity-100' : 'opacity-70'">Chat simple</a>
                                <a :href="links.complex" class="hp-button-secondary !px-3 !py-2 text-xs" :class="experience.key === 'complex' ? 'opacity-100' : 'opacity-70'">Chat completo</a>
                            </div>
                            <button type="button" @click="controlPanelOpen = false" class="hp-button-dark !px-3 !py-2 text-xs">
                                Cerrar
                            </button>
                        </div>
                    </x-historia.card>

                    <x-historia.card>
                        <button type="button" @click="contextPanelOpen = !contextPanelOpen" class="flex w-full items-center justify-between gap-3 text-left">
                            <div>
                                <h3 class="text-base font-semibold text-stone-950 dark:text-white">Contexto</h3>
                                <p class="mt-1 text-sm text-stone-600 dark:text-slate-300">Vault, escena y opciones base del chat.</p>
                            </div>
                            <span class="text-xs uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400" x-text="contextPanelOpen ? 'ocultar' : 'abrir'"></span>
                        </button>

                        <div class="mt-4 space-y-4" x-show="contextPanelOpen" x-transition.opacity>
                            <label class="block text-sm">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">Vault</span>
                                <select x-model="vaultId" @change="onVaultChanged()" class="hp-select">
                                    <option value="">Selecciona un vault</option>
                                    <template x-for="vault in (library.vaults ?? [])" :key="vault.id">
                                        <option :value="vault.id" x-text="vault.name"></option>
                                    </template>
                                </select>
                            </label>
                            <label class="block text-sm">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">Escena</span>
                                <select x-model="sceneId" @change="loadTimeline()" class="hp-select">
                                    <option value="">Selecciona una escena</option>
                                    <template x-for="scene in filteredScenes" :key="scene.id">
                                        <option :value="scene.id" x-text="scene.title"></option>
                                    </template>
                                </select>
                            </label>
                            <label class="block text-sm">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">Scene ID</span>
                                <input x-model="sceneId" class="hp-input" />
                            </label>
                            <template x-if="experience.show_continuity_id">
                                <label class="block text-sm">
                                    <span class="mb-1 block text-stone-600 dark:text-slate-300">Continuity ID</span>
                                    <input x-model="continuityId" class="hp-input" />
                                </label>
                            </template>
                            <template x-if="experience.show_mode_selector">
                                <label class="block text-sm">
                                    <span class="mb-1 block text-stone-600 dark:text-slate-300">Mode</span>
                                    <select x-model="mode" class="hp-select">
                                        <option value="write_scene">write_scene</option>
                                        <option value="chat">chat</option>
                                    </select>
                                </label>
                            </template>
                            <div class="hp-soft">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">Perfil activo</div>
                                <div class="mt-2 font-medium text-stone-900 dark:text-white">{{ auth()->user()?->name ?? 'Usuario MVP' }}</div>
                                <div class="mt-1 text-xs text-stone-500 dark:text-slate-400">
                                    #{{ auth()->id() ?? 1 }} · {{ auth()->user()?->email ?? 'mvp@historia.local' }}
                                </div>
                            </div>
                            <label class="flex items-center gap-3 rounded-2xl border border-stone-200 bg-stone-50 px-3 py-3 text-sm text-stone-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                <input x-model="apply" type="checkbox" class="rounded border-stone-300 dark:border-white/10 dark:bg-white/5" />
                                Aplicar cambios
                            </label>
                        </div>
                    </x-historia.card>

                    <x-historia.card>
                        <button type="button" @click="qaPanelOpen = !qaPanelOpen" class="flex w-full items-center justify-between gap-3 text-left">
                            <div>
                                <h3 class="text-base font-semibold text-stone-950 dark:text-white">QA y runtime</h3>
                                <p class="mt-1 text-sm text-stone-600 dark:text-slate-300">Loop QA, severidad y modelos activos.</p>
                            </div>
                            <span class="text-xs uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400" x-text="qaPanelOpen ? 'ocultar' : 'abrir'"></span>
                        </button>

                        <div class="mt-4 space-y-4" x-show="qaPanelOpen" x-transition.opacity>
                            <div class="flex flex-wrap gap-2">
                                <span class="rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em]" :class="deliveryBadgeTone" x-text="deliveryBadgeLabel"></span>
                                <span class="rounded-full border border-stone-200 bg-stone-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-stone-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400" x-text="experience.runtime_badge"></span>
                            </div>

                            <div class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm dark:border-white/10 dark:bg-white/5">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">Modelo QA</div>
                                <div class="mt-2 flex items-center justify-between gap-4">
                                    <span class="font-medium text-stone-900 dark:text-white" x-text="qaPolicyMode === 'auto' ? 'Automatico por defecto' : 'Manual por defecto'"></span>
                                    <span class="rounded-full border border-stone-200 px-3 py-1 text-[11px] uppercase tracking-[0.16em] text-stone-500 dark:border-white/10 dark:text-slate-400" x-text="qaModelCost"></span>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-4 text-sm dark:border-white/10 dark:bg-white/5">
                                <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400" x-text="experience.runtime_label"></div>
                                <dl class="mt-3 space-y-3">
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">Writer</dt>
                                        <dd class="mt-1 break-all font-medium text-stone-900 dark:text-white" x-text="activeRuntime.writer_model || 'sin definir'"></dd>
                                    </div>
                                    <div>
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">Summarizer</dt>
                                        <dd class="mt-1 break-all font-medium text-stone-900 dark:text-white" x-text="activeRuntime.summarizer_model || 'sin definir'"></dd>
                                    </div>
                                    <template x-if="experience.key === 'complex'">
                                        <div class="space-y-3">
                                            <div>
                                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">QA</dt>
                                                <dd class="mt-1 break-all font-medium text-stone-900 dark:text-white" x-text="activeRuntime.qa_model || 'sin definir'"></dd>
                                            </div>
                                            <div>
                                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">Director</dt>
                                                <dd class="mt-1 break-all font-medium text-stone-900 dark:text-white" x-text="activeRuntime.director_model || 'sin definir'"></dd>
                                            </div>
                                        </div>
                                    </template>
                                </dl>
                            </div>

                            <label class="flex items-center gap-3 rounded-2xl border border-stone-200 bg-stone-50 px-3 py-3 text-sm text-stone-700 dark:border-white/10 dark:bg-white/5 dark:text-slate-300">
                                <input x-model="qaLoopEnabled" type="checkbox" class="rounded border-stone-300 dark:border-white/10 dark:bg-white/5" />
                                Habilitar loop QA
                            </label>

                            <label class="block text-sm">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">Severidad minima</span>
                                <select x-model="qaLoopMinSeverity" class="hp-select">
                                    <option value="minor">minor</option>
                                    <option value="medium">medium</option>
                                    <option value="major">major</option>
                                </select>
                            </label>

                            <label class="block text-sm">
                                <span class="mb-1 block text-stone-600 dark:text-slate-300">Maximo de pasadas</span>
                                <select x-model="qaLoopMaxPasses" class="hp-select">
                                    <option value="1">1 pasada</option>
                                    <option value="2">2 pasadas</option>
                                    <option value="3">3 pasadas</option>
                                </select>
                            </label>
                        </div>
                    </x-historia.card>

                    <template x-if="experience.show_character_import">
                        <x-historia.card>
                            <button type="button" @click="castPanelOpen = !castPanelOpen" class="flex w-full items-center justify-between gap-3 text-left">
                                <div>
                                    <h3 class="text-base font-semibold text-stone-950 dark:text-white">Personajes y escena</h3>
                                    <p class="mt-1 text-sm text-stone-600 dark:text-slate-300">Quién está dentro de la escena y qué puedes importar.</p>
                                </div>
                                <span class="text-xs uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400" x-text="castPanelOpen ? 'ocultar' : 'abrir'"></span>
                            </button>

                            <div class="mt-4 space-y-6" x-show="castPanelOpen" x-transition.opacity>
                                <div class="space-y-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">Personajes en escena</div>
                                    <template x-if="attachedCharacters.length === 0">
                                        <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
                                            Esta escena aún no tiene personajes importados.
                                        </div>
                                    </template>

                                    <template x-for="character in attachedCharacters" :key="`attached-${character.id}`">
                                        <div class="rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                                            <div class="font-medium text-stone-900 dark:text-white" x-text="character.name"></div>
                                            <div class="mt-1 text-xs uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400" x-text="character.role || 'sin rol'"></div>
                                        </div>
                                    </template>
                                </div>

                                <div class="space-y-3">
                                    <div class="text-xs font-semibold uppercase tracking-[0.18em] text-stone-500 dark:text-slate-400">Importar personajes</div>
                                    <template x-if="filteredCharacters.length === 0">
                                        <div class="rounded-2xl border border-dashed border-stone-300 bg-stone-50 px-4 py-4 text-sm text-stone-500 dark:border-white/10 dark:bg-white/5 dark:text-slate-400">
                                            No hay personajes disponibles para importar en este vault.
                                        </div>
                                    </template>

                                    <template x-for="character in filteredCharacters.slice(0, 8)" :key="`import-${character.id}`">
                                        <div class="flex items-center justify-between gap-3 rounded-2xl border border-stone-200 bg-stone-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
                                            <div>
                                                <div class="font-medium text-stone-900 dark:text-white" x-text="character.name"></div>
                                                <div class="mt-1 text-xs text-stone-500 dark:text-slate-400" x-text="character.id"></div>
                                            </div>
                                            <button type="button" class="hp-button-secondary !px-3 !py-2 text-xs" @click="importCharacter(character.id)">
                                                Importar
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </x-historia.card>
                    </template>

                    <x-historia.card title="Estado y atajos">
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-sm text-stone-600 dark:text-slate-300">Métricas rápidas del contexto actual.</div>
                            <button type="button" @click="loadHealth()" class="hp-button-secondary !px-3 !py-2 text-xs">
                                Refrescar
                            </button>
                        </div>

                        <dl class="mt-4 space-y-3 text-sm">
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-stone-500 dark:text-slate-400">Commits</dt>
                                <dd class="font-medium text-stone-950 dark:text-white" x-text="timeline.commits.length"></dd>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-stone-500 dark:text-slate-400">Cambios</dt>
                                <dd class="font-medium text-stone-950 dark:text-white" x-text="timeline.stateChanges.length"></dd>
                            </div>
                        </dl>

                        <div class="mt-5 flex flex-wrap gap-2">
                            <a :href="links.timeline" class="hp-button-sky !px-3 !py-2 text-xs">Timeline</a>
                            <a :href="links.continuity" class="hp-button-emerald !px-3 !py-2 text-xs">Continuidad</a>
                            <a :href="links.vaults" class="hp-button-dark !px-3 !py-2 text-xs">Vaults</a>
                            <a :href="links.characters" class="hp-button-dark !px-3 !py-2 text-xs">Personajes</a>
                            <a :href="links.settings" class="hp-button-fuchsia !px-3 !py-2 text-xs">Settings</a>
                        </div>
                    </x-historia.card>
                </div>
            </aside>
        </div>
    </x-historia.page-shell>

    <div
        x-cloak
        x-show="showContextModal"
        class="fixed inset-0 z-[100] flex items-center justify-center bg-stone-900/60 p-4 backdrop-blur-sm"
    >
        <div
            class="relative flex h-full max-h-[85vh] w-full max-w-4xl flex-col rounded-3xl bg-white shadow-2xl dark:bg-stone-950"
            @click.away="showContextModal = false"
        >
            <div class="flex items-center justify-between border-b border-stone-200 px-6 py-4 dark:border-white/10">
                <h2 class="text-lg font-semibold text-stone-900 dark:text-white">Contexto de la Escena</h2>
                <button type="button" @click="showContextModal = false" class="text-stone-500 hover:text-stone-700 dark:text-stone-400 dark:hover:text-stone-200">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="flex-1 overflow-y-auto p-6">
                <pre class="whitespace-pre-wrap rounded-xl bg-stone-100 p-4 text-xs text-stone-800 dark:bg-stone-900 dark:text-stone-300" x-text="JSON.stringify(currentContextData, null, 2)"></pre>
            </div>
            <div class="border-t border-stone-200 px-6 py-4 dark:border-white/10 flex justify-end">
                <button type="button" @click="showContextModal = false" class="hp-button-secondary !px-4 !py-2">
                    Cerrar
                </button>
            </div>
        </div>
    </div>
</div>

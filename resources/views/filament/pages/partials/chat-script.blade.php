<script>
    (function registerFilamentHistoriaChat() {
        if (window.filamentHistoriaChat) {
            return;
        }

        function csrfToken() {
            return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        }

        async function jsonRequest(url, options = {}) {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    ...(options.headers ?? {}),
                },
                ...options,
            });

            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(typeof payload?.error === 'string' ? payload.error : `HTTP ${response.status}`);
            }

            return payload;
        }

        async function streamSseRequest(url, options = {}, handlers = {}) {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/event-stream',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    ...(options.headers ?? {}),
                },
                ...options,
            });

            if (!response.ok || !response.body) {
                throw new Error(`HTTP ${response.status}`);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            const emitBlock = (block) => {
                const lines = block.split('\n');
                let event = 'message';
                const dataLines = [];

                for (const line of lines) {
                    if (line.startsWith('event:')) event = line.slice(6).trim();
                    if (line.startsWith('data:')) dataLines.push(line.slice(5).trimStart());
                }

                if (dataLines.length === 0) return;

                let payload = dataLines.join('\n');

                try {
                    payload = JSON.parse(payload);
                } catch (error) {
                    // keep raw string
                }

                handlers.onEvent?.(event, payload);
            };

            while (true) {
                const { value, done } = await reader.read();
                buffer += decoder.decode(value || new Uint8Array(), { stream: !done });

                let separatorIndex = buffer.indexOf('\n\n');

                while (separatorIndex !== -1) {
                    const block = buffer.slice(0, separatorIndex).trim();
                    buffer = buffer.slice(separatorIndex + 2);

                    if (block !== '') emitBlock(block);

                    separatorIndex = buffer.indexOf('\n\n');
                }

                if (done) {
                    const tail = buffer.trim();
                    if (tail !== '') emitBlock(tail);
                    break;
                }
            }
        }

        function buildMessage(role, content, meta = '') {
            return {
                id: `${role}-${Date.now()}-${Math.random().toString(16).slice(2)}`,
                role,
                content: String(content ?? ''),
                meta: String(meta ?? ''),
            };
        }

        function parseIntegerOrNull(value) {
            const candidate = String(value ?? '').trim();
            if (candidate === '') return null;

            const number = Number(candidate);

            return Number.isInteger(number) && number > 0 ? number : null;
        }

        function registerChatEngine() {
            if (typeof window.Alpine === 'undefined' || window.Alpine.store('chatEngine')) {
                return;
            }

            window.Alpine.store('chatEngine', {
                // --- Estado del motor de turnos ---
                sceneId: '',
                status: '',           // 'draft' | 'ready' | 'in_progress' | ''
                currentTurnCharacterId: null,
                roundNumber: 1,
                characters: [],       // {id, name, scene_role, controlled_by_user_id, initiative_score, has_acted_this_round}
                loading: false,
                _pollTimer: null,
                _endpointUrl: '',
                _currentUserId: null,

                // --- Computed helpers ---
                get isActive() {
                    return this.status === 'ready' || this.status === 'in_progress';
                },
                get playerCharacters() {
                    const uid = this._currentUserId ? Number(this._currentUserId) : null;
                    return this.characters.filter((c) => {
                        const isPlayer = c.scene_role === 'player';
                        if (uid === null) return isPlayer;
                        return isPlayer && Number(c.controlled_by_user_id) === uid;
                    });
                },
                get currentTurnHolder() {
                    if (!this.currentTurnCharacterId) return null;
                    return this.characters.find((c) => c.id === this.currentTurnCharacterId) ?? null;
                },
                get isMyTurn() {
                    if (!this.isActive || !this.currentTurnCharacterId) return false;
                    return this.playerCharacters.some((c) => c.id === this.currentTurnCharacterId);
                },
                get inputBlocked() {
                    // Bloquear si la escena es activa y NO es el turno del jugador
                    if (!this.isActive) return false;
                    if (this.playerCharacters.length === 0) return false;
                    return !this.isMyTurn;
                },
                get turnLabel() {
                    if (!this.isActive) return '';
                    if (!this.currentTurnCharacterId) return `Ronda ${this.roundNumber} — esperando turno…`;
                    const holder = this.currentTurnHolder;
                    const name = holder ? holder.name : this.currentTurnCharacterId;
                    return this.isMyTurn
                        ? `✦ Tu turno: ${name} (Ronda ${this.roundNumber})`
                        : `Turno de ${name} (Ronda ${this.roundNumber})`;
                },

                // --- API ---
                async loadSceneState() {
                    if (!this.sceneId || !this._endpointUrl) return;
                    this.loading = true;
                    try {
                        const url = `${this._endpointUrl}?scene_id=${encodeURIComponent(this.sceneId)}`;
                        const res = await fetch(url, {
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                            },
                        });
                        if (!res.ok) return;
                        const data = await res.json();
                        this.status = String(data.status ?? '');
                        this.currentTurnCharacterId = data.currentTurnCharacterId ?? null;
                        this.roundNumber = Number(data.roundNumber ?? 1);
                        this.characters = Array.isArray(data.characters) ? data.characters : [];
                    } catch (_) {
                        // silencioso — no romper el chat por un fallo de polling
                    } finally {
                        this.loading = false;
                    }
                },

                startPolling(intervalMs = 5000) {
                    this.stopPolling();
                    this._pollTimer = setInterval(() => this.loadSceneState(), intervalMs);
                },

                stopPolling() {
                    if (this._pollTimer !== null) {
                        clearInterval(this._pollTimer);
                        this._pollTimer = null;
                    }
                },

                setScene(sceneId, endpointUrl, userId = null) {
                    const changed = this.sceneId !== String(sceneId ?? '');
                    this.sceneId = String(sceneId ?? '');
                    this._endpointUrl = String(endpointUrl ?? '');
                    this._currentUserId = userId;

                    if (this.sceneId !== '') {
                        this.loadSceneState();
                        if (changed) this.startPolling(6000);
                    } else {
                        this.status = '';
                        this.currentTurnCharacterId = null;
                        this.roundNumber = 1;
                        this.characters = [];
                        this.stopPolling();
                    }
                },
            });
        }

        // Registrar el store tan pronto Alpine esté disponible
        if (typeof window.Alpine !== 'undefined') {
            registerChatEngine();
        } else {
            document.addEventListener('alpine:init', registerChatEngine);
        }

        window.filamentHistoriaChat = function filamentHistoriaChat({ experience = {}, endpoints = {}, defaults = {}, links = {}, qa = {}, runtime = {}, library = {} } = {}) {
            return {
                experience,
                endpoints,
                links,
                runtime,
                vaultId: String(defaults.vault_id ?? ''),
                sceneId: String(defaults.scene_id ?? ''),
                continuityId: String(defaults.continuity_id ?? ''),
                userId: String(defaults.user_id ?? ''),
                mode: String(defaults.mode ?? 'write_scene'),
                apply: Boolean(defaults.apply ?? true),
                library,
                qaLoopEnabled: Boolean(qa.enabled ?? false),
                qaLoopMaxPasses: Number(qa.max_passes ?? 2),
                qaLoopMinSeverity: String(qa.min_severity ?? 'medium'),
                qaPolicyMode: String(qa.policy_mode ?? 'manual'),
                qaModelCost: String(qa.model_cost ?? 'desconocido'),
                controlPanelOpen: false,
                contextPanelOpen: false,
                qaPanelOpen: false,
                castPanelOpen: false,
                creatingScene: false,
                sending: false,
                draft: '',
                newSceneId: '',
                newSceneTitle: '',
                newSceneLocationId: '',
                newSceneQuestId: '',
                newSceneQuestPrompt: '',
                error: '',
                messages: [],
                health: { status: '' },
                timeline: { turns: [], commits: [], stateChanges: [] },
                questStatuses: [],
                currentContextData: null,
                showContextModal: false,
                get hasChatContext() {
                    return this.vaultId.trim() !== '' && this.sceneId.trim() !== '';
                },
                get canSend() {
                    return this.hasChatContext && this.draft.trim() !== '';
                },
                get filteredScenes() {
                    const vaultId = this.vaultId.trim();
                    const scenes = Array.isArray(this.library.scenes) ? this.library.scenes : [];
                    if (vaultId === '') return scenes;
                    return scenes.filter((scene) => String(scene.vault_id ?? '') === vaultId);
                },
                get filteredCharacters() {
                    const vaultId = this.vaultId.trim();
                    const sceneId = this.sceneId.trim();
                    const characters = Array.isArray(this.library.characters) ? this.library.characters : [];
                    const attachedIds = new Set((this.sceneCharacters[sceneId] ?? []).map((character) => String(character.id)));
                    return characters.filter((character) => {
                        const sameVault = vaultId === '' || String(character.vault_id ?? '') === vaultId;
                        return sameVault && !attachedIds.has(String(character.id));
                    });
                },
                get filteredLocations() {
                    const vaultId = this.vaultId.trim();
                    const locations = Array.isArray(this.library.locations) ? this.library.locations : [];
                    if (vaultId === '') return locations;
                    return locations.filter((location) => String(location.vault_id ?? '') === vaultId);
                },
                get filteredQuests() {
                    const vaultId = this.vaultId.trim();
                    const quests = Array.isArray(this.library.quests) ? this.library.quests : [];
                    if (vaultId === '') return quests;
                    return quests.filter((quest) => String(quest.vault_id ?? '') === vaultId);
                },
                get attachedCharacters() {
                    return this.sceneCharacters[this.sceneId.trim()] ?? [];
                },
                get sceneCharacters() {
                    return this.library.scene_characters ?? {};
                },
                get deliveryBadgeLabel() {
                    return this.qaLoopEnabled ? 'QA consolidado' : 'Streaming activo';
                },
                get deliveryBadgeTone() {
                    return this.qaLoopEnabled
                        ? 'border-fuchsia-300 bg-fuchsia-50 text-fuchsia-700 dark:border-fuchsia-400/30 dark:bg-fuchsia-400/10 dark:text-fuchsia-200'
                        : 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-400/30 dark:bg-emerald-400/10 dark:text-emerald-200';
                },
                get activeRuntime() {
                    const key = String(this.experience?.key ?? 'simple');
                    return this.runtime?.[key] ?? this.runtime?.simple ?? {};
                },
                init() {
                    if (this.vaultId.trim() === '' && (this.library.vaults ?? []).length > 0) {
                        this.vaultId = String(this.library.vaults[0]?.id ?? '');
                    }

                    if (this.vaultId.trim() === '' && this.sceneId.trim() !== '') {
                        const matchingScene = (this.library.scenes ?? []).find((scene) => String(scene.id) === this.sceneId.trim());
                        if (matchingScene) {
                            this.vaultId = String(matchingScene.vault_id ?? '');
                        }
                    }

                    this.ensureSceneBelongsToVault();
                    this.ensureBootstrapDefaults();
                    this.loadHealth().catch(() => {});
                    this.loadTimeline().catch(() => {});

                    // Inicializar el motor de turnos con la escena actual
                    this._syncChatEngine();

                    // Observar cambios de escena para resincronizar el motor
                    this.$watch('sceneId', () => this._syncChatEngine());

                    if (window.matchMedia?.('(min-width: 1280px)')?.matches) {
                        this.controlPanelOpen = false;
                    }
                },
                _syncChatEngine() {
                    const engine = this.$store?.chatEngine;
                    if (!engine) return;
                    engine.setScene(
                        this.sceneId.trim(),
                        this.endpoints?.sceneState ?? '',
                        parseIntegerOrNull(this.userId),
                    );
                },
                ensureSceneBelongsToVault() {
                    const currentSceneId = this.sceneId.trim();
                    const matchingScene = this.filteredScenes.find((scene) => String(scene.id) === currentSceneId);
                    if (matchingScene) {
                        return;
                    }

                    this.sceneId = this.filteredScenes[0]?.id ?? '';
                },
                ensureBootstrapDefaults() {
                    if (this.newSceneLocationId.trim() === '' && this.filteredLocations.length > 0) {
                        this.newSceneLocationId = String(this.filteredLocations[0]?.id ?? '');
                    }

                    if (this.newSceneQuestId.trim() !== '' && this.newSceneQuestPrompt.trim() === '') {
                        return;
                    }
                },
                async createSceneRecord() {
                    if (this.creatingScene || this.vaultId.trim() === '') return;

                    const sceneId = this.newSceneId.trim();
                    if (sceneId === '') {
                        this.error = 'Escribe un Scene ID para crear la escena.';
                        return;
                    }

                    const locationId = this.newSceneLocationId.trim();
                    if (locationId === '') {
                        this.error = 'Selecciona una location para bootstrap de escena.';
                        return;
                    }

                    const questId = this.newSceneQuestId.trim();
                    const questPrompt = this.newSceneQuestPrompt.trim();
                    if (questId === '' && questPrompt === '') {
                        this.error = 'Selecciona una quest existente o escribe un quest prompt.';
                        return;
                    }

                    this.creatingScene = true;
                    this.error = '';

                    try {
                        const payload = await jsonRequest(this.endpoints.sceneBootstrap, {
                            method: 'POST',
                            body: JSON.stringify({
                                scene_id: sceneId,
                                vault_id: this.vaultId.trim(),
                                title: this.newSceneTitle.trim(),
                                location_id: locationId,
                                quest_id: questId || null,
                                quest_prompt: questId === '' ? questPrompt : null,
                            }),
                        });

                        if (payload?.scene) {
                            const scenes = [...(this.library.scenes ?? [])];
                            const existingIndex = scenes.findIndex((scene) => String(scene.id) === String(payload.scene.id));

                            if (existingIndex === -1) {
                                scenes.push(payload.scene);
                            } else {
                                scenes[existingIndex] = payload.scene;
                            }

                            this.library.scenes = scenes.sort((left, right) => String(left.title ?? '').localeCompare(String(right.title ?? '')));
                            this.sceneId = String(payload.scene.id ?? '');
                            this.newSceneId = '';
                            this.newSceneTitle = '';
                            this.newSceneQuestId = '';
                            this.newSceneQuestPrompt = '';
                            this.castPanelOpen = true;
                            this.controlPanelOpen = false;
                            if (payload?.quest && payload.quest.generated) {
                                this.library.quests = [
                                    ...(this.library.quests ?? []).filter((quest) => String(quest.id) !== String(payload.quest.questId)),
                                    {
                                        id: String(payload.quest.questId ?? ''),
                                        title: String(payload.quest.title ?? payload.quest.questId ?? ''),
                                        vault_id: this.vaultId.trim(),
                                    },
                                ].sort((left, right) => String(left.title ?? '').localeCompare(String(right.title ?? '')));
                            }
                            await this.loadTimeline();
                        }
                    } catch (error) {
                        this.error = error instanceof Error ? error.message : 'No se pudo crear la escena';
                    } finally {
                        this.creatingScene = false;
                    }
                },
                currentContext(userMessage = null) {
                    const payload = {
                        vault_id: this.vaultId.trim(),
                        scene_id: this.sceneId.trim(),
                        mode: this.mode,
                        apply: this.apply,
                    };

                    if (this.continuityId.trim() !== '') payload.continuity_id = this.continuityId.trim();

                    const userId = parseIntegerOrNull(this.userId);
                    if (userId !== null) payload.user_id = userId;

                    if (userMessage !== null) payload.user_message = String(userMessage).trim();

                    payload.qa_loop_enabled = this.qaLoopEnabled;
                    payload.qa_loop_max_passes = Math.min(3, Math.max(1, Number(this.qaLoopMaxPasses || 1)));
                    payload.qa_loop_min_severity = this.qaLoopMinSeverity;

                    return payload;
                },
                onVaultChanged() {
                    this.ensureSceneBelongsToVault();
                    this.ensureBootstrapDefaults();
                    this.loadTimeline().catch(() => {});
                },
                async importCharacter(characterId) {
                    if (this.sceneId.trim() === '' || this.sending) return;

                    this.error = '';

                    try {
                        const payload = await jsonRequest(this.endpoints.attachSceneCharacter, {
                            method: 'POST',
                            body: JSON.stringify({
                                scene_id: this.sceneId.trim(),
                                character_id: String(characterId).trim(),
                            }),
                        });

                        this.library.scene_characters = {
                            ...(this.library.scene_characters ?? {}),
                            [this.sceneId.trim()]: payload.characters ?? [],
                        };
                    } catch (error) {
                        this.error = error instanceof Error ? error.message : 'No se pudo importar el personaje';
                    }
                },
                async loadHealth() {
                    this.health = await jsonRequest(this.endpoints.health, { method: 'GET' });
                },
                async loadContext() {
                    if (this.sceneId.trim() === '') {
                        this.currentContextData = null;
                        return;
                    }

                    const params = new URLSearchParams({ scene_id: this.sceneId.trim() });
                    if (this.continuityId.trim() !== '') params.set('continuity_id', this.continuityId.trim());

                    try {
                        const payload = await jsonRequest(`${this.endpoints.sceneContext}?${params.toString()}`, { method: 'GET' });
                        this.currentContextData = payload.context ?? null;
                    } catch (error) {
                        this.currentContextData = null;
                        console.error('Error loading context:', error);
                    }
                },
                async loadTimeline() {
                    if (this.sceneId.trim() === '') {
                        this.timeline = { turns: [], commits: [], stateChanges: [] };
                        this.questStatuses = [];
                        this.currentContextData = null;
                        return;
                    }

                    const params = new URLSearchParams({ scene_id: this.sceneId.trim() });
                    if (this.continuityId.trim() !== '') params.set('continuity_id', this.continuityId.trim());

                    const payload = await jsonRequest(`${this.endpoints.timeline}?${params.toString()}`, { method: 'GET' });
                    this.timeline = {
                        turns: payload.turns ?? [],
                        commits: payload.commits ?? [],
                        stateChanges: payload.stateChanges ?? [],
                    };
                    this.questStatuses = payload.quests ?? [];
                    this.loadContext().catch(() => {});
                },
                async createScene() {
                    if (!this.canSend || this.sending) return;

                    const userMessage = this.draft.trim();
                    this.messages.push(buildMessage('user', userMessage, `${this.sceneId.trim()} · scene_create`));
                    this.draft = '';
                    this.sending = true;
                    this.error = '';

                    try {
                        const payload = await jsonRequest(this.endpoints.sceneCreate, {
                            method: 'POST',
                            body: JSON.stringify(this.currentContext(userMessage)),
                        });

                        if (payload?.scene) {
                            const exists = (this.library.scenes ?? []).some((scene) => String(scene.id) === String(payload.scene.id));
                            if (!exists) {
                                this.library.scenes = [
                                    ...(this.library.scenes ?? []),
                                    payload.scene,
                                ].sort((left, right) => String(left.title ?? '').localeCompare(String(right.title ?? '')));
                            }

                            if (String(this.vaultId || '') === '' && String(payload.scene.vault_id || '') !== '') {
                                this.vaultId = String(payload.scene.vault_id);
                            }
                        }

                        this.messages.push(buildMessage('assistant', payload.outputMd ?? 'Sin salida.', payload.sceneType ?? 'simple'));
                        await this.loadTimeline();
                    } catch (error) {
                        this.error = error instanceof Error ? error.message : 'Error ejecutando escena';
                    } finally {
                        this.sending = false;
                    }
                },
                async sendChat() {
                    if (!this.canSend || this.sending) return;

                    const userMessage = this.draft.trim();
                    this.messages.push(buildMessage('user', userMessage, this.sceneId.trim()));
                    this.draft = '';
                    this.sending = true;
                    this.error = '';

                    const assistantMessage = buildMessage('assistant', '', 'streaming');
                    this.messages.push(assistantMessage);

                    try {
                        await streamSseRequest(this.endpoints.chatStream, {
                            method: 'POST',
                            body: JSON.stringify(this.currentContext(userMessage)),
                        }, {
                            onEvent: (event, payload) => {
                                if (event === 'chunk') {
                                    assistantMessage.content += String(payload?.delta ?? '');
                                    return;
                                }

                                if (event === 'done') {
                                    assistantMessage.content = String(payload?.outputMd ?? assistantMessage.content ?? '').trim();

                                    const meta = [];
                                    if (payload?.continuityId) meta.push(`cont ${payload.continuityId}`);
                                    if (payload?.turnIndex) meta.push(`turn ${payload.turnIndex}`);
                                    if (payload?.commitId) meta.push(`commit ${payload.commitId}`);
                                    assistantMessage.meta = meta.join(' · ') || 'completado';

                                    assistantMessage.questDirective = payload?.questDirective ?? null;
                                    assistantMessage.questUpdateSummary = payload?.questUpdateSummary ?? null;
                                    assistantMessage.eventTriggers = payload?.eventTriggers ?? null;
                                    assistantMessage.characterStatusUpdateSummary = payload?.characterStatusUpdateSummary ?? null;
                                }

                                if (event === 'error') {
                                    throw new Error(String(payload?.error ?? 'Error de streaming'));
                                }
                            },
                        });

                        await this.loadTimeline();

                        this.$nextTick(() => {
                            this.$refs.chatScroll?.scrollTo?.({ top: this.$refs.chatScroll.scrollHeight, behavior: 'smooth' });
                        });
                    } catch (error) {
                        this.error = error instanceof Error ? error.message : 'Error ejecutando chat';
                        assistantMessage.meta = 'error';
                        assistantMessage.content = `Error: ${this.error}`;
                    } finally {
                        this.sending = false;
                    }
                },
            };
        };
    })();
</script>

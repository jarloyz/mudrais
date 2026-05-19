<?php

namespace App\Filament\Pages;

use App\Models\AgentConfig;
use App\Models\AiProvider;
use App\Models\Player;
use App\Models\User;
use App\Support\AgentCatalog;
use App\Support\OpenRouterModelCatalog;
use App\Support\WorkspaceContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SettingsPage extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Settings';

    protected static string | \UnitEnum | null $navigationGroup = 'Sistema';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'settings';

    protected string $view = 'filament.pages.settings-page';

    public string $configScope = 'player'; // 'global' | 'player' | 'vault' | 'scene'
    public string $sceneId = '';
    public string $continuityId = '';
    public string $userId = '';
    public string $vaultId = '';
    public string $mode = 'write_scene';
    public bool $apply = true;
    public string $writerModel = '';
    public string $qaModel = '';
    public string $timeoutMs = '';
    public string $writerTemperature = '';
    public string $writerMaxOutputTokens = '';
    public string $writerTopP = '';
    public string $writerPresencePenalty = '';
    public string $writerFrequencyPenalty = '';
    public string $writerStyleProfile = '';
    public string $writerStyleNotes = '';
    public string $writerResponseLength = '';
    public string $qaPolicySimple = 'adaptive';
    public string $qaPolicyComplex = 'adaptive';
    // ── Preset management (scope global) ────────────────────────────────────
    public string $presetName = '';
    public string $selectedPresetId = '';
    /** Slug del proveedor LLM global (campo provider de AgentConfig). */
    public string $globalProviderSlug = '';
    /**
     * @var array<int, array{id:string,name:string,active:bool}>
     */
    public array $globalPresets = [];
    /**
     * @var array<string, string>
     */
    public array $providerOptions = [];
    /**
     * @var array<int, array{id:string,name:string,email:string}>
     */
    public array $availableUsers = [];
    /**
     * @var array<int, array{key:string,label:string,model:string,enabled:bool,section:string}>
     */
    public array $agentCatalog = [];
    /**
     * @var array<string, string>
     */
    public array $agentModels = [];
    /**
     * @var array<string, string>  agentKey → provider slug ('' = usa ruta global)
     */
    public array $agentProviders = [];
    /**
     * @var array<string, bool>  agentKey → reasoning habilitado
     */
    public array $agentReasoning = [];
    /**
     * @var array<string, int>  agentKey → budget_tokens para reasoning
     */
    public array $agentReasoningBudget = [];
    /** Driver del guard de safety: 'llm' | 'openai_moderation' */
    public string $safetyDriver = 'llm';
    /**
     * @var array<string, string>
     */
    public array $openRouterModelOptions = [];
    /**
     * @var array<string, array<string, mixed>>
     */
    public array $openRouterModelLookup = [];

    public function mount(): void
    {
        $defaults = WorkspaceContext::defaults();
        $this->sceneId = $defaults['scene_id'];
        $this->continuityId = $defaults['continuity_id'];
        $this->userId = $defaults['user_id'] ?? '';
        $this->vaultId = $defaults['vault_id'] ?? '';
        $this->mode = $defaults['mode'];
        $this->apply = $defaults['apply'];
        $catalog = app(AgentCatalog::class);
        $this->agentCatalog = $catalog->all();
        $this->agentModels = $catalog->modelMap();
        $this->agentProviders      = array_fill_keys(array_column($this->agentCatalog, 'key'), '');
        $this->agentReasoning       = array_fill_keys(array_column($this->agentCatalog, 'key'), false);
        $this->agentReasoningBudget = array_fill_keys(array_column($this->agentCatalog, 'key'), 8000);
        $this->providerOptions = Schema::hasTable('ai_providers')
            ? AiProvider::slugOptions()
            : ['openrouter' => 'OpenRouter'];
        $this->writerModel = $this->agentModels['writer'] ?? '';
        $this->qaModel = $this->agentModels['critic'] ?? '';
        $this->timeoutMs = '120000';
        $this->writerTemperature = '0.7';
        $this->writerMaxOutputTokens = '4000';
        $this->writerTopP = '1';
        $this->writerPresencePenalty = '0.15';
        $this->writerFrequencyPenalty = '0.1';
        $this->writerStyleProfile = 'cinematico';
        $this->writerStyleNotes = '';
        $this->writerResponseLength = 'medio';
        $this->qaPolicySimple = 'adaptive';
        $this->qaPolicyComplex = 'adaptive';
        $this->openRouterModelLookup = $this->buildUnifiedModelCatalog();
        $this->openRouterModelOptions = array_map(
            fn (array $m): string => (string) ($m['option_label'] ?? $m['id']),
            $this->openRouterModelLookup,
        );
        $this->refreshGlobalPresetsList();
        if (Schema::hasTable('agent_configs')) {
            $this->globalProviderSlug = (string) (AgentConfig::globalInstance()->provider ?? '');
        }

        if (Schema::hasTable('players')) {
            $this->availableUsers = Player::query()
                ->orderBy('username')
                ->get(['id', 'username'])
                ->map(static fn (Player $player): array => [
                    'id'    => (string) $player->id,
                    'name'  => $player->username,
                    'email' => $player->username,
                ])
                ->all();
        } else {
            $this->availableUsers = [];
        }

        $this->loadConfigForScope();
    }

    public function refreshOpenRouterCatalog(): void
    {
        $this->openRouterModelLookup = $this->buildUnifiedModelCatalog(forceRefresh: true);
        $this->openRouterModelOptions = array_map(
            fn (array $m): string => (string) ($m['option_label'] ?? $m['id']),
            $this->openRouterModelLookup,
        );
        $this->dispatch('hp-models-updated', models: array_values($this->openRouterModelLookup));

        Notification::make()
            ->title('Catálogo actualizado')
            ->success()
            ->send();
    }

    /**
     * Catálogo unificado: OpenRouter (dinámico) + default_model de cada AiProvider configurado.
     * Cada entrada de proveedor externo incluye 'provider_slug' para enrutar al endpoint correcto.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildUnifiedModelCatalog(bool $forceRefresh = false): array
    {
        $catalog = app(OpenRouterModelCatalog::class)->lookup(forceRefresh: $forceRefresh);

        // Marcar modelos de OpenRouter con source para distinguirlos en el combobox.
        foreach ($catalog as &$entry) {
            $entry['source'] = 'openrouter';
        }
        unset($entry);

        if (Schema::hasTable('ai_providers')) {
            AiProvider::query()->get()
                ->each(function (AiProvider $provider) use (&$catalog, $forceRefresh): void {
                    // Modelos nativos con driver google: poblar desde la API de Google.
                    if ($provider->driver === 'google' && filled($provider->api_key)) {
                        foreach ($this->fetchGoogleModels($provider, $forceRefresh) as $key => $model) {
                            $catalog[$key] = $model;
                        }
                        return;
                    }

                    // Cualquier otro provider con default_model: entrada única en el catálogo.
                    $modelId = trim((string) ($provider->default_model ?? ''));
                    if ($modelId === '') {
                        return;
                    }
                    $catalog[$provider->slug] = [
                        'id'                    => $modelId,
                        'name'                  => $provider->name,
                        'description'           => $provider->name,
                        'context_length'        => 0,
                        'max_completion_tokens' => 0,
                        'pricing'               => ['prompt' => '0', 'completion' => '0', 'request' => '0'],
                        'price_label'           => 'Configurado',
                        'option_label'          => $provider->name . ' · ' . $modelId,
                        'provider_slug'         => $provider->slug,
                        'provider_name'         => $provider->name,
                        'source'                => 'direct',
                    ];
                });
        }

        Log::debug('[SettingsPage@buildUnifiedModelCatalog] Catálogo unificado construido', [
            'total' => count($catalog),
        ]);

        return $this->seedMissingModels($catalog);
    }

    public function saveContext(): void
    {
        WorkspaceContext::store([
            'scene_id' => trim($this->sceneId),
            'continuity_id' => trim($this->continuityId),
            'user_id' => (string) ($this->normalizeUserId() ?? ''),
            'mode' => $this->mode,
            'apply' => $this->apply,
        ]);

        Notification::make()
            ->title('Contexto guardado')
            ->success()
            ->send();
    }

    public function loadConfigForScope(): void
    {
        try {
            if (! Schema::hasTable('agent_configs')) {
                Notification::make()->title('Falta la tabla agent_configs')->body('Corre php artisan migrate.')->warning()->send();

                return;
            }

            if ($this->configScope === 'player' && $this->normalizeUserId() === null) {
                Notification::make()->title('Selecciona un perfil de jugador primero')->warning()->send();

                return;
            }

            if ($this->configScope === 'vault' && trim($this->vaultId) === '') {
                Notification::make()->title('Ingresa un Vault ID primero')->warning()->send();

                return;
            }

            if ($this->configScope === 'scene' && trim($this->sceneId) === '') {
                Notification::make()->title('Ingresa un Activity ID primero')->warning()->send();

                return;
            }

            $config = $this->queryForScope()->first();

            if (! $config) {
                Notification::make()->title('No hay configuración guardada para este scope')->warning()->send();

                return;
            }

            $this->mapConfigToProperties($config);
            Notification::make()->title('Configuración cargada')->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('No se pudo cargar la configuración')->body($exception->getMessage())->danger()->send();
        }
    }

    public function saveConfigForScope(): void
    {
        try {
            if (! Schema::hasTable('agent_configs')) {
                Notification::make()->title('Falta la tabla agent_configs')->body('Corre php artisan migrate.')->danger()->send();

                return;
            }

            if ($this->configScope === 'player') {
                $userId = $this->normalizeUserId();
                if ($userId === null) {
                    Notification::make()->title('No hay un perfil autenticado válido')->danger()->send();

                    return;
                }
                Player::query()->findOrFail($userId);
            }

            if ($this->configScope === 'vault' && trim($this->vaultId) === '') {
                Notification::make()->title('Ingresa un Vault ID primero')->danger()->send();

                return;
            }

            if ($this->configScope === 'scene' && trim($this->sceneId) === '') {
                Notification::make()->title('Ingresa un Activity ID primero')->danger()->send();

                return;
            }

            $criteria = match ($this->configScope) {
                // 'active' => true garantiza que updateOrCreate apunta al preset activo,
                // no al primer registro global que encuentre (puede haber múltiples presets).
                'global' => ['scope' => 'global', 'active' => true],
                'player' => ['scope' => 'player', 'player_id' => $this->normalizeUserId()],
                'vault'  => ['scope' => 'vault',  'vault_id'  => trim($this->vaultId)],
                'scene'  => ['scope' => 'scene',  'scene_id'  => trim($this->sceneId)],
            };

            AgentConfig::query()->updateOrCreate($criteria, $this->buildConfigPayload($this->configScope));

            if ($this->configScope === 'global') {
                Cache::forget('ai_active_provider');
                $newSlug = trim($this->globalProviderSlug);
                if ($newSlug !== '') {
                    Cache::forget("ai_provider_{$newSlug}");
                }
            }

            Notification::make()->title('Configuración guardada')->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('No se pudo guardar la configuración')->body($exception->getMessage())->danger()->send();
        }
    }

    // ── Preset management ────────────────────────────────────────────────────

    private function refreshGlobalPresetsList(): void
    {
        if (! Schema::hasTable('agent_configs')) {
            $this->globalPresets = [];
            return;
        }

        $this->globalPresets = AgentConfig::allGlobalPresets()
            ->map(fn (AgentConfig $c): array => [
                'id'     => (string) $c->id,
                'name'   => (string) ($c->name ?? 'Sin nombre'),
                'active' => (bool) $c->active,
            ])
            ->all();

        // Seleccionar el activo por defecto si no hay ninguno seleccionado
        if ($this->selectedPresetId === '') {
            foreach ($this->globalPresets as $p) {
                if ($p['active']) {
                    $this->selectedPresetId = $p['id'];
                    break;
                }
            }
        }
    }

    public function loadSelectedPreset(): void
    {
        if (! Schema::hasTable('agent_configs') || $this->selectedPresetId === '') {
            Notification::make()->title('Selecciona un preset primero')->warning()->send();
            return;
        }

        $config = AgentConfig::query()->where('scope', 'global')->find($this->selectedPresetId);
        if (! $config) {
            Notification::make()->title('Preset no encontrado')->danger()->send();
            return;
        }

        $this->mapConfigToProperties($config);
        Notification::make()->title('Preset cargado')->success()->send();
    }

    public function saveAsPreset(): void
    {
        if (! Schema::hasTable('agent_configs')) {
            Notification::make()->title('Falta la tabla agent_configs')->danger()->send();
            return;
        }

        $name = trim($this->presetName);
        if ($name === '') {
            Notification::make()->title('Escribe un nombre para el preset')->warning()->send();
            return;
        }

        try {
            $payload = $this->buildConfigPayload('global');
            AgentConfig::query()->create(array_merge($payload, [
                'scope'  => 'global',
                'name'   => $name,
                'active' => false,
            ]));
            $this->presetName = '';
            $this->refreshGlobalPresetsList();
            Notification::make()->title("Preset «{$name}» guardado")->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al guardar preset')->body($e->getMessage())->danger()->send();
        }
    }

    public function updateSelectedPreset(): void
    {
        if ($this->selectedPresetId === '') {
            Notification::make()->title('Selecciona un preset primero')->warning()->send();
            return;
        }

        try {
            $config = AgentConfig::query()->where('scope', 'global')->find($this->selectedPresetId);
            if (! $config) {
                Notification::make()->title('Preset no encontrado')->danger()->send();
                return;
            }

            $config->update($this->buildConfigPayload('global'));
            if ($config->active) {
                Cache::forget('ai_active_provider');
                $newSlug = trim($this->globalProviderSlug);
                if ($newSlug !== '') {
                    Cache::forget("ai_provider_{$newSlug}");
                }
            }
            $this->refreshGlobalPresetsList();
            Notification::make()->title("Preset «{$config->name}» actualizado")->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al actualizar preset')->body($e->getMessage())->danger()->send();
        }
    }

    public function activateSelectedPreset(): void
    {
        if ($this->selectedPresetId === '') {
            Notification::make()->title('Selecciona un preset primero')->warning()->send();
            return;
        }

        try {
            $config = AgentConfig::query()->where('scope', 'global')->find($this->selectedPresetId);
            if (! $config) {
                Notification::make()->title('Preset no encontrado')->danger()->send();
                return;
            }

            // Leer el slug anterior antes de activar para invalidar también su caché.
            $oldSlug = (string) (AgentConfig::globalInstance()->provider ?? '');
            $config->activateAsGlobal();
            $this->globalProviderSlug = (string) ($config->provider ?? '');
            Cache::forget('ai_active_provider');
            if ($oldSlug !== '') {
                Cache::forget("ai_provider_{$oldSlug}");
            }
            if ($this->globalProviderSlug !== '' && $this->globalProviderSlug !== $oldSlug) {
                Cache::forget("ai_provider_{$this->globalProviderSlug}");
            }
            $this->refreshGlobalPresetsList();
            Notification::make()->title("Preset «{$config->name}» activado")->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al activar preset')->body($e->getMessage())->danger()->send();
        }
    }

    public function deleteSelectedPreset(): void
    {
        if ($this->selectedPresetId === '') {
            Notification::make()->title('Selecciona un preset primero')->warning()->send();
            return;
        }

        try {
            $config = AgentConfig::query()->where('scope', 'global')->find($this->selectedPresetId);
            if (! $config) {
                Notification::make()->title('Preset no encontrado')->danger()->send();
                return;
            }

            if ($config->active) {
                Notification::make()->title('No puedes eliminar el preset activo')->warning()->send();
                return;
            }

            $name = $config->name;
            $config->delete();
            $this->selectedPresetId = '';
            $this->refreshGlobalPresetsList();
            Notification::make()->title("Preset «{$name}» eliminado")->success()->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Error al eliminar preset')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<AgentConfig>
     */
    private function queryForScope(): \Illuminate\Database\Eloquent\Builder
    {
        return match ($this->configScope) {
            'global' => AgentConfig::query()->where('scope', 'global')->where('active', true),
            'player' => AgentConfig::query()->where('scope', 'player')->where('player_id', $this->normalizeUserId()),
            'vault'  => AgentConfig::query()->where('scope', 'vault')->where('vault_id', trim($this->vaultId)),
            'scene'  => AgentConfig::query()->where('scope', 'scene')->where('scene_id', trim($this->sceneId)),
        };
    }

    public function updatedAgentModels(string $key): void
    {
        $this->syncPrimaryModelsFromAgentMap();
        // agentProviders[$key] se actualiza directamente desde Alpine al seleccionar un modelo
    }

    /**
     * Inject stub entries for any model IDs that are configured in historia.php
     * but not present in the OpenRouter catalog (e.g. embedding models, local models).
     * This ensures they are always searchable in the combobox.
     *
     * @param array<string, array<string, mixed>> $lookup
     * @return array<string, array<string, mixed>>
     */
    private function seedMissingModels(array $lookup): array
    {
        $configuredModels = $this->agentModels;

        foreach ($configuredModels as $modelId) {
            $modelId = trim((string) $modelId);

            if ($modelId === '' || isset($lookup[$modelId])) {
                continue;
            }

            $lookup[$modelId] = [
                'id'                    => $modelId,
                'name'                  => $modelId,
                'description'           => '',
                'context_length'        => 0,
                'max_completion_tokens' => 0,
                'pricing'               => ['prompt' => '0', 'completion' => '0', 'request' => '0'],
                'price_label'           => 'Configurado localmente',
                'option_label'          => $modelId . ' · Configurado localmente',
            ];
        }

        return $lookup;
    }

    private function mapConfigToProperties(Model $config): void
    {
        $catalog = app(AgentCatalog::class);
        $this->globalProviderSlug = (string) ($config->provider ?? '');
        $this->writerModel = (string) ($config->writer_model ?? '');
        $this->qaModel = (string) ($config->qa_model ?? '');
        $this->timeoutMs = (string) ($config->timeout_ms ?? '120000');
        $this->agentModels = $catalog->modelMap();

        $agentSettings = $config->settings_json['agents'] ?? null;
        if (is_array($agentSettings)) {
            foreach ($agentSettings as $key => $settings) {
                if (! is_array($settings)) {
                    continue;
                }
                if (is_string($settings['model'] ?? null) && trim((string) $settings['model']) !== '') {
                    $this->agentModels[(string) $key] = trim((string) $settings['model']);
                }
                if (is_string($settings['provider'] ?? null) && trim((string) $settings['provider']) !== '') {
                    $this->agentProviders[(string) $key] = trim((string) $settings['provider']);
                }
                if (isset($settings['reasoning']) && is_bool($settings['reasoning'])) {
                    $this->agentReasoning[(string) $key] = $settings['reasoning'];
                }
                if (isset($settings['budget_tokens']) && is_numeric($settings['budget_tokens'])) {
                    $this->agentReasoningBudget[(string) $key] = (int) $settings['budget_tokens'];
                }
            }
        }

        $writerParameters = $config->settings_json['parameters']['writer'] ?? null;
        if (is_array($writerParameters)) {
            $this->writerTemperature = (string) ($writerParameters['temperature'] ?? '0.7');
            $this->writerMaxOutputTokens = (string) ($writerParameters['max_output_tokens'] ?? '4000');
            $this->writerTopP = (string) ($writerParameters['top_p'] ?? '1');
            $this->writerPresencePenalty = (string) ($writerParameters['presence_penalty'] ?? '0.15');
            $this->writerFrequencyPenalty = (string) ($writerParameters['frequency_penalty'] ?? '0.1');
            $this->writerStyleProfile = (string) ($writerParameters['style_profile'] ?? 'cinematico');
            $this->writerStyleNotes = (string) ($writerParameters['style_notes'] ?? '');
            $this->writerResponseLength = (string) ($writerParameters['response_length'] ?? 'medio');
        }

        $qaPolicy = $config->settings_json['qa_policy'] ?? null;
        if (is_array($qaPolicy)) {
            $this->qaPolicySimple = (string) ($qaPolicy['simple'] ?? 'adaptive');
            $this->qaPolicyComplex = (string) ($qaPolicy['complex'] ?? 'adaptive');
        }

        $safetyDriver = $config->settings_json['safety_driver'] ?? null;
        if (is_string($safetyDriver) && in_array($safetyDriver, ['llm', 'openai_moderation'], true)) {
            $this->safetyDriver = $safetyDriver;
        }

        $this->syncPrimaryModelsFromAgentMap();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConfigPayload(string $scope = 'player'): array
    {
        $this->syncPrimaryModelsFromAgentMap();

        return [
            'provider' => trim($this->globalProviderSlug) ?: null,
            'writer_model' => trim($this->writerModel) ?: null,
            'qa_model' => trim($this->qaModel) ?: null,
            'timeout_ms' => is_numeric($this->timeoutMs) ? (int) $this->timeoutMs : null,
            'settings_json' => [
                'saved_from'    => 'filament.settings.' . $scope,
                'agents'        => $this->buildAgentPayload(),
                'parameters'    => [
                    'writer' => $this->buildWriterParameterPayload(),
                ],
                'qa_policy'     => [
                    'simple'  => $this->normalizeQaPolicy($this->qaPolicySimple),
                    'complex' => $this->normalizeQaPolicy($this->qaPolicyComplex),
                ],
                'safety_driver' => in_array($this->safetyDriver, ['llm', 'openai_moderation'], true)
                    ? $this->safetyDriver
                    : 'llm',
            ],
        ];
    }

    private function normalizeUserId(): ?string
    {
        $value = trim($this->userId);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function buildAgentPayload(): array
    {
        $payload = [];

        foreach ($this->agentCatalog as $agent) {
            $key   = $agent['key'];
            $entry = ['model' => trim((string) ($this->agentModels[$key] ?? $agent['model']))];
            $slug  = trim((string) ($this->agentProviders[$key] ?? ''));
            if ($slug !== '') {
                $entry['provider'] = $slug;
            }
            $reasoning = $this->agentReasoning[$key] ?? false;
            $entry['reasoning'] = (bool) $reasoning;
            if ($reasoning) {
                $entry['budget_tokens'] = max(1000, (int) ($this->agentReasoningBudget[$key] ?? 8000));
            }
            $payload[$key] = $entry;
        }

        return $payload;
    }

    private function syncPrimaryModelsFromAgentMap(): void
    {
        $writer = trim((string) ($this->agentModels['writer'] ?? $this->writerModel));
        $qa = trim((string) ($this->agentModels['qa'] ?? $this->qaModel));

        if ($writer !== '') {
            $this->writerModel = $writer;
            $this->agentModels['writer'] = $writer;
        }

        if ($qa !== '') {
            $this->qaModel = $qa;
            $this->agentModels['qa'] = $qa;
        }
    }

    private function normalizeQaPolicy(string $value): string
    {
        $value = trim($value);

        return in_array($value, ['adaptive', 'auto', 'manual', 'disabled'], true)
            ? $value
            : 'adaptive';
    }

    /**
     * @return array<string, float|int|string>
     */
    private function buildWriterParameterPayload(): array
    {
        return [
            'temperature' => is_numeric($this->writerTemperature) ? (float) $this->writerTemperature : 0.7,
            'max_output_tokens' => is_numeric($this->writerMaxOutputTokens) ? (int) $this->writerMaxOutputTokens : 4000,
            'top_p' => is_numeric($this->writerTopP) ? (float) $this->writerTopP : 1.0,
            'presence_penalty' => is_numeric($this->writerPresencePenalty) ? (float) $this->writerPresencePenalty : 0.15,
            'frequency_penalty' => is_numeric($this->writerFrequencyPenalty) ? (float) $this->writerFrequencyPenalty : 0.1,
            'style_profile' => trim($this->writerStyleProfile) !== '' ? trim($this->writerStyleProfile) : 'cinematico',
            'style_notes' => trim($this->writerStyleNotes),
            'response_length' => trim($this->writerResponseLength) !== '' ? trim($this->writerResponseLength) : 'medio',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getRuntimeConfigProperty(): array
    {
        $modelCatalog = app(OpenRouterModelCatalog::class);
        $criticModelId = trim((string) ($this->agentModels['critic'] ?? $this->qaModel));
        $qaIsFree = $modelCatalog->isFreeModel($criticModelId);
        $resolver = app(\App\Support\UserAiSettingsResolver::class);
        $resolved = $resolver->resolve($this->normalizeUserId(), $this->vaultId ?: null, $this->sceneId ?: null);

        return [
            'writer_model' => $this->writerModel,
            'qa_model' => $this->qaModel,
            'timeout_ms' => $this->timeoutMs,
            'openrouter_key' => filled(config('historia.ai.openrouter.api_key')),
            'anthropic_key' => filled(config('historia.ai.anthropic.api_key')),
            'google_key' => filled(config('historia.ai.google.api_key')),
            'writer_style_profile' => $this->writerStyleProfile,
            'writer_response_length' => $this->writerResponseLength,
            'critic_model' => $resolved['models']['critic'] ?? '',
            'critic_mode' => $resolver->resolveQaExecutionMode($this->normalizeUserId(), 'simple', $qaIsFree, $this->vaultId ?: null, $this->sceneId ?: null),
            'qa_model_is_free' => $qaIsFree,
        ];
    }

    public function getCurrentUserProperty(): ?User
    {
        return Auth::user();
    }

    /**
     * @return array{key:string,label:string,model:string,enabled:bool,section:string}|null
     */
    public function getSimpleModeAgentProperty(): ?array
    {
        foreach ($this->agentCatalog as $agent) {
            if (($agent['key'] ?? null) === 'writer') {
                return $agent;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{key:string,label:string,model:string,enabled:bool,section:string}>
     */
    public function getPipelineAgentsProperty(): array
    {
        return array_values(array_filter(
            $this->agentCatalog,
            static fn (array $agent): bool => ($agent['key'] ?? null) !== 'writer',
        ));
    }

    /**
     * Agents grouped by section, excluding the writer (handled separately).
     *
     * @return array<string, array<int, array{key:string,label:string,model:string,enabled:bool,section:string}>>
     */
    public function getAgentsBySectionProperty(): array
    {
        $groups = [];

        foreach ($this->agentCatalog as $agent) {
            if ($agent['key'] === 'writer') {
                continue;
            }

            $groups[$agent['section']][] = $agent;
        }

        return $groups;
    }

    /**
     * Consulta la API de Google para listar los modelos Gemini disponibles.
     * Cachea el resultado 1 hora por provider. Solo incluye modelos que soportan generateContent.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fetchGoogleModels(AiProvider $provider, bool $forceRefresh = false): array
    {
        $cacheKey = 'ai_google_models_' . $provider->slug;

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addHour(), function () use ($provider): array {
            try {
                $apiKey = trim((string) ($provider->api_key ?? ''));
                if ($apiKey === '') {
                    return [];
                }

                $response = Http::timeout(10)
                    ->acceptJson()
                    ->get('https://generativelanguage.googleapis.com/v1beta/models', ['key' => $apiKey]);

                if (! $response->successful()) {
                    Log::warning('[SettingsPage@fetchGoogleModels] API error', [
                        'provider' => $provider->slug,
                        'status'   => $response->status(),
                    ]);
                    return [];
                }

                $result = [];

                foreach ($response->json('models') ?? [] as $model) {
                    $methods = $model['supportedGenerationMethods'] ?? [];
                    if (! in_array('generateContent', $methods, true)) {
                        continue;
                    }

                    // "models/gemini-2.5-pro" → "gemini-2.5-pro"
                    $id          = preg_replace('#^models/#', '', (string) ($model['name'] ?? ''));
                    $displayName = (string) ($model['displayName'] ?? $id);

                    $result[$provider->slug . ':' . $id] = [
                        'id'                    => $id,
                        'name'                  => $displayName,
                        'description'           => (string) ($model['description'] ?? ''),
                        'context_length'        => (int) ($model['inputTokenLimit'] ?? 0),
                        'max_completion_tokens' => (int) ($model['outputTokenLimit'] ?? 0),
                        'pricing'               => ['prompt' => '0', 'completion' => '0', 'request' => '0'],
                        'price_label'           => $provider->name . ' directo',
                        'option_label'          => $displayName . ' · ' . $provider->name,
                        'provider_slug'         => $provider->slug,
                        'provider_name'         => $provider->name,
                        'source'                => 'direct',
                    ];
                }

                Log::debug('[SettingsPage@fetchGoogleModels] Modelos cargados', [
                    'provider' => $provider->slug,
                    'count'    => count($result),
                ]);

                return $result;
            } catch (Throwable $e) {
                Log::warning('[SettingsPage@fetchGoogleModels] Excepción', [
                    'provider' => $provider->slug,
                    'error'    => $e->getMessage(),
                ]);
                return [];
            }
        });
    }
}

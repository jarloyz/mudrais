<?php

namespace App\Filament\Pages;

use App\Infrastructure\Persistence\Eloquent\Models\CharacterRecord;
use App\Infrastructure\Persistence\Eloquent\Models\LocationRecord;
use App\Models\Quest;
use App\Infrastructure\Persistence\Eloquent\Models\SceneRecord;
use App\Infrastructure\Persistence\Eloquent\Models\VaultRecord;
use App\Support\OpenRouterModelCatalog;
use App\Support\UserAiSettingsResolver;
use App\Support\WorkspaceContext;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;

abstract class BaseChatPage extends Page
{
    /**
     * @var array{vault_id:string,scene_id:string,continuity_id:string,user_id:string,mode:string,apply:bool}
     */
    public array $context = [];

    public function mount(): void
    {
        $this->context = WorkspaceContext::defaults();

        $requestedVault = trim((string) request()->query('vault', ''));
        $requestedScene = trim((string) request()->query('scene', ''));
        $requestedContinuity = trim((string) request()->query('continuity', ''));

        if ($requestedVault !== '') {
            $this->context['vault_id'] = $requestedVault;
        }

        if ($requestedScene !== '') {
            $this->context['scene_id'] = $requestedScene;
        }

        if ($requestedContinuity !== '') {
            $this->context['continuity_id'] = $requestedContinuity;
        }

        $this->context['mode'] = $this->defaultMode();
    }

    /**
     * @return array<string, mixed>
     */
    public function getChatConfig(): array
    {
        $userId = auth()->id();
        $resolver = app(UserAiSettingsResolver::class);
        $modelCatalog = app(OpenRouterModelCatalog::class);
        $qaModel = $resolver->resolveAgentModel($userId, 'qa');
        $writerModel = $resolver->resolveAgentModel($userId, 'writer');
        $summarizerModel = $resolver->resolveAgentModel($userId, 'summarizer');
        $qaIsFree = $modelCatalog->isFreeModel($qaModel);
        $qaMode = $resolver->resolveQaExecutionMode($userId, $this->experienceKey(), $qaIsFree);

        return [
            'experience' => $this->experienceConfig(),
            'endpoints' => [
                'health' => url('/api/health'),
                'chat' => url('/api/v2/chat'),
                'chatStream' => url('/api/v2/chat/stream'),
                'sceneBootstrap' => url('/api/v2/scene/bootstrap'),
                'sceneCreate' => url('/api/v2/scene/create'),
                'sceneContext' => url('/api/v2/scene/context'),
                'attachSceneCharacter' => url('/api/v2/scene/characters/attach'),
                'timeline' => url('/api/v2/timeline'),
                'sceneState' => url('/api/v2/scene/state'),
            ],
            'defaults' => $this->context,
            'qa' => [
                'enabled' => $qaMode === 'auto',
                'max_passes' => 2,
                'min_severity' => 'medium',
                'policy_mode' => $qaMode,
                'model_cost' => $qaIsFree === true ? 'gratis' : ($qaIsFree === false ? 'de pago' : 'desconocido'),
            ],
            'runtime' => [
                'simple' => [
                    'writer_model' => $writerModel,
                    'summarizer_model' => $summarizerModel,
                ],
                'complex' => [
                    'writer_model' => $writerModel,
                    'summarizer_model' => $summarizerModel,
                    'qa_model' => $qaModel,
                    'director_model' => $resolver->resolveAgentModel($userId, 'director'),
                    'editor_model' => $resolver->resolveAgentModel($userId, 'editor'),
                    'statekeeper_model' => $resolver->resolveAgentModel($userId, 'statekeeper'),
                ],
            ],
            'library' => $this->buildLibraryConfig(),
            'links' => [
                'simple' => ChatPage::getUrl(panel: 'admin'),
                'complex' => ComplexChatPage::getUrl(panel: 'admin'),
                'timeline' => TimelinePage::getUrl(panel: 'admin'),
                'continuity' => ContinuityPage::getUrl(panel: 'admin'),
                'settings' => SettingsPage::getUrl(panel: 'admin'),
                'vaults' => \App\Filament\Resources\Vaults\VaultResource::getUrl('index', panel: 'admin'),
                'characters' => \App\Filament\Resources\Characters\CharacterResource::getUrl('index', panel: 'admin'),
            ],
        ];
    }

    abstract protected function experienceKey(): string;

    abstract protected function experienceConfig(): array;

    protected function defaultMode(): string
    {
        return 'write_scene';
    }

    /**
     * @return array{
     *   vaults:array<int, array{id:string,name:string}>,
     *   scenes:array<int, array{id:string,title:string,vault_id:string}>,
     *   locations:array<int, array{id:string,name:string,vault_id:string}>,
     *   quests:array<int, array{id:string,title:string,vault_id:string}>,
     *   characters:array<int, array{id:string,name:string,vault_id:string}>,
     *   scene_characters:array<string, array<int, array{id:string,name:string,role:string}>>
     * }
     */
    private function buildLibraryConfig(): array
    {
        if (! Schema::hasTable('vaults') || ! Schema::hasTable('activities') || ! Schema::hasTable('avatars')) {
            return [
                'vaults' => [],
                'activities' => [],
                'locations' => [],
                'quests' => [],
                'characters' => [],
                'activity_members' => [],
            ];
        }

        $vaults = VaultRecord::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (VaultRecord $vault): array => [
                'id' => $vault->id,
                'name' => $vault->name,
            ])
            ->all();

        $activities = SceneRecord::query()
            ->orderBy('title')
            ->get(['id', 'title', 'vault_id'])
            ->map(static fn (SceneRecord $scene): array => [
                'id' => $scene->id,
                'title' => $scene->title ?: $scene->id,
                'vault_id' => (string) $scene->vault_id,
            ])
            ->all();

        $locations = Schema::hasTable('locations')
            ? LocationRecord::query()
                ->orderBy('name')
                ->get(['id', 'name', 'vault_id'])
                ->map(static fn (LocationRecord $location): array => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'vault_id' => (string) $location->vault_id,
                ])
                ->all()
            : [];

        $quests = Schema::hasTable('quests')
            ? Quest::query()
                ->orderBy('title')
                ->get(['id', 'title', 'vault_id'])
                ->map(static fn (Quest $quest): array => [
                    'id' => $quest->id,
                    'title' => $quest->title ?: $quest->id,
                    'vault_id' => (string) $quest->vault_id,
                ])
                ->all()
            : [];

        $characters = CharacterRecord::query()
            ->orderBy('name')
            ->get(['id', 'name', 'vault_id'])
            ->map(static fn (CharacterRecord $character): array => [
                'id' => $character->id,
                'name' => $character->name,
                'vault_id' => (string) $character->vault_id,
            ])
            ->all();

        $sceneCharacters = SceneRecord::query()
            ->with('characters:id,name')
            ->get()
            ->mapWithKeys(static fn (SceneRecord $scene): array => [
                $scene->id => $scene->characters
                    ->map(static fn ($character): array => [
                        'id' => $character->id,
                        'name' => $character->name,
                        'role' => (string) ($character->pivot->role ?? ''),
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();

        return [
            'vaults' => $vaults,
            'activities' => $activities,
            'locations' => $locations,
            'quests' => $quests,
            'characters' => $characters,
            'activity_members' => $sceneCharacters,
        ];
    }
}

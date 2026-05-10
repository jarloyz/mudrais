<?php

namespace Tests\Unit\Support;

use App\Models\AgentConfig;
use App\Models\Player;
use App\Support\UserAiSettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserAiSettingsResolverTest extends TestCase
{
    use RefreshDatabase;

    private UserAiSettingsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(UserAiSettingsResolver::class);
    }

    public function test_returns_empty_models_when_no_db_config_exists(): void
    {
        $result = $this->resolver->resolve(null, null, null);

        $this->assertSame('openrouter', $result['provider']);
        $this->assertSame((string) config('historia.ai.models.writer', ''), $result['models']['writer']);
        $this->assertSame((string) config('historia.ai.models.critic', ''), $result['models']['critic']);
        $this->assertSame(0.7, $result['parameters']['writer']['temperature']);
    }

    public function test_global_config_overrides_env_defaults(): void
    {
        AgentConfig::query()->create([
            'scope'        => 'global',
            'active'       => true,
            'writer_model' => 'custom/global-model',
            'qa_model'     => null,
        ]);

        $result = $this->resolver->resolve(null, null, null);

        $this->assertSame('custom/global-model', $result['models']['writer']);
        $this->assertSame('custom/global-model', $result['agents']['writer']);
    }

    public function test_player_config_overrides_global(): void
    {
        AgentConfig::query()->create(['scope' => 'global', 'active' => true, 'writer_model' => 'global/model']);
        $player = Player::factory()->create();
        AgentConfig::query()->create([
            'scope'        => 'player',
            'player_id'    => $player->id,
            'writer_model' => 'player/model',
        ]);

        $result = $this->resolver->resolve($player->id);

        $this->assertSame('player/model', $result['models']['writer']);
    }

    public function test_vault_config_overrides_player(): void
    {
        $player = Player::factory()->create();
        AgentConfig::query()->create([
            'scope'        => 'player',
            'player_id'    => $player->id,
            'settings_json' => ['parameters' => ['writer' => ['temperature' => 0.5]]],
        ]);

        $vault = \App\Models\Vault::query()->create(['id' => 'vault_test_01', 'name' => 'Test Vault']);
        AgentConfig::query()->create([
            'scope'         => 'vault',
            'vault_id'      => $vault->id,
            'settings_json' => ['parameters' => ['writer' => ['temperature' => 0.9]]],
        ]);

        $result = $this->resolver->resolve($player->id, $vault->id);

        $this->assertSame(0.9, $result['parameters']['writer']['temperature']);
    }

    public function test_scene_config_overrides_vault(): void
    {
        $vault = \App\Models\Vault::query()->create(['id' => 'vault_test_02', 'name' => 'Test Vault 2']);
        AgentConfig::query()->create(['scope' => 'vault', 'vault_id' => $vault->id, 'qa_model' => 'vault/qa']);

        $scene = \App\Models\Activity::query()->create(['id' => 'scene_test_01', 'vault_id' => $vault->id, 'title' => 'Test Activity']);
        AgentConfig::query()->create(['scope' => 'scene', 'scene_id' => $scene->id, 'qa_model' => 'scene/critic']);

        $result = $this->resolver->resolve(null, $vault->id, $scene->id);

        // qa_model column maps to critic in the new agent architecture
        $this->assertSame('scene/critic', $result['models']['critic']);
    }

    public function test_partial_scene_config_does_not_wipe_player_values(): void
    {
        $player = Player::factory()->create();
        AgentConfig::query()->create([
            'scope'         => 'player',
            'player_id'     => $player->id,
            'settings_json' => [
                'parameters' => ['writer' => ['style_profile' => 'sobrio', 'temperature' => 0.6]],
            ],
        ]);

        $vault = \App\Models\Vault::query()->create(['id' => 'vault_test_03', 'name' => 'Test Vault 3']);
        $scene = \App\Models\Activity::query()->create(['id' => 'scene_test_02', 'vault_id' => $vault->id, 'title' => 'Test Activity 2']);
        AgentConfig::query()->create([
            'scope'         => 'scene',
            'scene_id'      => $scene->id,
            'settings_json' => ['parameters' => ['writer' => ['temperature' => 0.3]]],
        ]);

        $result = $this->resolver->resolve($player->id, null, $scene->id);

        $this->assertSame('sobrio', $result['parameters']['writer']['style_profile']);
        $this->assertSame(0.3, $result['parameters']['writer']['temperature']);
    }

    public function test_missing_tables_do_not_throw(): void
    {
        Schema::dropIfExists('agent_configs');

        $result = $this->resolver->resolve(1, 'vault_x', 'scene_x');

        $this->assertArrayHasKey('provider', $result);
        $this->assertArrayHasKey('models', $result);
    }

    public function test_resolve_agent_model_uses_full_hierarchy(): void
    {
        $vault = \App\Models\Vault::query()->create(['id' => 'vault_test_04', 'name' => 'Vault 4']);
        $scene = \App\Models\Activity::query()->create(['id' => 'scene_test_03', 'vault_id' => $vault->id, 'title' => 'Activity 3']);
        AgentConfig::query()->create([
            'scope'         => 'scene',
            'scene_id'      => $scene->id,
            'settings_json' => ['agents' => ['writer' => ['model' => 'scene/writer-v2']]],
        ]);

        $model = $this->resolver->resolveAgentModel(null, 'writer', $vault->id, $scene->id);

        $this->assertSame('scene/writer-v2', $model);
    }

    public function test_resolve_qa_execution_mode_respects_scene_policy(): void
    {
        $vault = \App\Models\Vault::query()->create(['id' => 'vault_test_05', 'name' => 'Vault 5']);
        $scene = \App\Models\Activity::query()->create(['id' => 'scene_test_04', 'vault_id' => $vault->id, 'title' => 'Activity 4']);
        AgentConfig::query()->create([
            'scope'         => 'scene',
            'scene_id'      => $scene->id,
            'settings_json' => ['qa_policy' => ['simple' => 'disabled', 'complex' => 'auto']],
        ]);

        $this->assertSame('disabled', $this->resolver->resolveQaExecutionMode(null, 'simple', false, null, $scene->id));
        $this->assertSame('auto', $this->resolver->resolveQaExecutionMode(null, 'complex', false, null, $scene->id));
    }

    public function test_global_config_instance_creates_row_if_absent(): void
    {
        $this->assertSame(0, AgentConfig::query()->where('scope', 'global')->count());

        AgentConfig::globalInstance();
        AgentConfig::globalInstance();

        $this->assertSame(1, AgentConfig::query()->where('scope', 'global')->count());
    }

    public function test_empty_string_vault_and_scene_do_not_trigger_db_lookup(): void
    {
        AgentConfig::query()->create(['scope' => 'global', 'active' => true, 'writer_model' => 'global/model']);

        // Si pasamos strings vacíos, resolveHierarchy no debería incluirlos en el OR.
        // Lo verificamos porque si fallara, intentaría buscar scope='player' con player_id=''
        // lo cual daría error en PostgreSQL.
        $result = $this->resolver->resolve('', '', '');

        $this->assertSame('global/model', $result['models']['writer']);
    }

    public function test_integer_player_id_is_not_expected_but_handled_as_string(): void
    {
        // Creamos el player para que la FK no falle
        $playerId = '019ddb24-1848-7f74-8451-225bc05cb73e';
        \Illuminate\Support\Facades\DB::table('players')->insert([
            'id' => $playerId,
            'username' => 'testuser',
            'discord_id' => '123456',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AgentConfig::query()->create([
            'scope' => 'player',
            'player_id' => $playerId,
            'writer_model' => 'player/model'
        ]);

        // Probamos que se use correctamente
        $result = $this->resolver->resolve($playerId, null, null);

        $this->assertSame('player/model', $result['models']['writer']);
    }
}

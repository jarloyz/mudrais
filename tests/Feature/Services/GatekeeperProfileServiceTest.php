<?php

namespace Tests\Feature\Services;

use App\Application\Contracts\AiChatGateway;
use App\Application\Services\GatekeeperProfileService;
use App\Application\Services\TagNormalizerService;
use App\Infrastructure\Ai\Agents\ProfileTranslatorAgent;
use App\Jobs\IndexPlayerStyleJob;
use App\Jobs\NormalizePlayerTagsJob;
use App\Models\Player;
use App\Support\UserAiSettingsResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\SeedsPromptTemplates;
use Tests\TestCase;

class GatekeeperProfileServiceTest extends TestCase
{
    use RefreshDatabase, SeedsPromptTemplates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPromptTemplates();
    }

    public function test_it_processes_and_saves_player_profile(): void
    {
        Bus::fake();

        $player = Player::create([
            'discord_id' => '123456',
            'username'   => 'Tester',
        ]);

        // Profile text is too malformed for regex → will hit AI fallback (one chat call)
        $profileText = "MUDRAIS | Ficha de Identidad ... Edad: 25 ... Nacionalidad: España ...";

        $mockGateway = $this->createMock(AiChatGateway::class);
        $mockGateway->expects($this->once())
            ->method('chat')
            ->willReturn([
                'text' => json_encode([
                    'age'              => 25,
                    'nationality'      => 'Spain',
                    'experience_level' => 'Veteran',   // already in English (prompt instructs so)
                    'schedule'         => ['nights' => true],
                    'verbosity'        => 'High',
                    'red_lines'        => ['gore'],
                    'affinities'       => ['Fantasy'],
                    'raw_profile'      => 'I am a tester.',
                ]),
            ]);

        $mockResolver = $this->createMock(UserAiSettingsResolver::class);
        $mockResolver->method('resolveAgentModel')->willReturn('test-model');

        // Translator is not called on AI fallback path (AI already returns English)
        $mockTranslator = $this->createMock(ProfileTranslatorAgent::class);
        $mockTranslator->expects($this->never())->method('translate');

        $agent = new \App\Infrastructure\Ai\Agents\GatekeeperProfileAgent(
            $mockGateway,
            $mockResolver,
            new \App\Infrastructure\Ai\Parsers\ProfileTemplateParser(),
            $mockTranslator,
        );
        $mockTagNormalizer = $this->createMock(TagNormalizerService::class);
        $service = new GatekeeperProfileService($agent, $mockTagNormalizer);

        // processPlayerProfile ahora retorna PlayerArchetypeProfile
        $profile = $service->processPlayerProfile($player, $profileText);

        $this->assertNotNull($profile);

        // Campos contextuales ahora viven en el perfil
        $this->assertEquals(['nights' => true], $profile->schedule);
        $this->assertEquals(['gore'], $profile->red_lines);
        $this->assertEquals(['Fantasy'], $profile->positive_prefs);
        $this->assertEquals('I am a tester.', $profile->raw_profile);
        $this->assertFalse($profile->is_vectorized);

        // experience_level y verbosity_level ahora están en profile.metadata
        $this->assertEquals(3, $profile->metadata['experience_level']);
        $this->assertEquals(5, $profile->metadata['verbosity_level']);
        $this->assertEquals('I am a tester.', $profile->preference_profile);

        // Campos globales siguen en players
        $this->assertDatabaseHas('players', ['discord_id' => '123456', 'age' => 25]);
        $player->refresh();
        $this->assertEquals(25, $player->age);
        $this->assertEquals('Spain', $player->nationality);

        Bus::assertChained([NormalizePlayerTagsJob::class, IndexPlayerStyleJob::class]);
    }

    public function test_translator_called_on_regex_path(): void
    {
        Bus::fake();

        $player = Player::create(['discord_id' => '999', 'username' => 'RegexPlayer']);

        // Well-formed template — regex will parse it completely
        $profileText = "**DATOS BÁSICOS**\n* Edad: 30\n* Nacionalidad: México\n* Experiencia: Veterano\n\n**LOGÍSTICA Y ESTILO**\n* Horarios disponibles: L-V 21:00 UTC-6\n* Extensión: Alta/Biblias\n* Líneas Rojas: gore\n\n**TUS AFINIDADES**\n1. Fantasía épica\n\n**ESTILO NARRATIVO**\nMe especializo en narrativas oscuras.";

        $mockGateway  = $this->createMock(AiChatGateway::class);
        $mockGateway->expects($this->once()) // translator calls chat once
            ->method('chat')
            ->willReturn(['text' => '{"red_lines":["gore"],"preferences":["epic fantasy"],"raw_profile":"I specialize in dark narratives."}']);

        $mockResolver = $this->createMock(UserAiSettingsResolver::class);
        $mockResolver->method('resolveAgentModel')->willReturn('test-model');

        $translator = new \App\Infrastructure\Ai\Agents\ProfileTranslatorAgent($mockGateway, $mockResolver);

        $agent = new \App\Infrastructure\Ai\Agents\GatekeeperProfileAgent(
            $mockGateway,
            $mockResolver,
            new \App\Infrastructure\Ai\Parsers\ProfileTemplateParser(),
            $translator,
        );
        $mockTagNormalizer = $this->createMock(TagNormalizerService::class);
        $service = new GatekeeperProfileService($agent, $mockTagNormalizer);

        $profile = $service->processPlayerProfile($player, $profileText);

        $this->assertNotNull($profile);
        $this->assertEquals(['epic fantasy'], $profile->positive_prefs);  // translated
        $this->assertStringContainsStringIgnoringCase('narrativas oscuras', $profile->raw_profile); // stored in original language
    }
}

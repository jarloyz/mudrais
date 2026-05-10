<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Character;

use App\Application\UseCases\Character\UpsertCharacterProfileInput;
use App\Infrastructure\Character\SqlCharacterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqlCharacterRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Manually insert the default vault since it's created by a separate migration (013_vault_users_memberships.sql)
        // that isn't included in the direct scope of our current Laravel migrations.
        DB::table('vaults')->insertOrIgnore([
            'id' => 'vault_default',
            'name' => 'Vault Default',
            'status' => 'active',
            'description' => 'Vault por defecto para pruebas locales',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function testGetCharacterProfileReturnsCorrectData(): void
    {
        $repository = new SqlCharacterRepository();

        // Seed data using public_facade
        $traits = [
            [
                'key' => 'personality',
                'title' => 'Personality',
                'sort_order' => 1,
                'bullets' => [
                    ['body' => 'Brave', 'section' => null, 'sort_order' => 1]
                ]
            ]
        ];

        DB::table('avatars')->insert([
            'id' => 'test_character',
            'name' => 'Test Avatar',
            'vault_id' => 'vault_default',
            'public_facade' => json_encode($traits),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tags')->insert(['name' => 'hero']);
        $tagId = DB::table('tags')->where('name', 'hero')->value('id');
        DB::table('character_tags')->insert([
            'character_id' => 'test_character',
            'tag_id' => $tagId,
            'context_id' => null,
            'created_at' => now(),
        ]);

        $profile = $repository->getCharacterProfile('test_character', null);

        $this->assertIsArray($profile);
        $this->assertEquals('test_character', $profile['id']);
        $this->assertEquals('Test Avatar', $profile['name']);
        $this->assertContains('hero', $profile['tags']);
        $this->assertCount(1, $profile['traits']);
        $this->assertEquals('personality', $profile['traits'][0]['key']);
        $this->assertCount(1, $profile['traits'][0]['bullets']);
        $this->assertEquals('Brave', $profile['traits'][0]['bullets'][0]['text']);
    }

    public function testUpsertCharacterProfileCreatesNewCharacter(): void
    {
        $repository = new SqlCharacterRepository();

        $inputData = [
            'id' => 'new_char',
            'name' => 'New Avatar',
            'context_id' => null,
            'tags' => ['new_tag'],
            'traits' => [
                [
                    'key' => 'appearance',
                    'title' => 'Appearance',
                    'bullets' => ['Tall', 'Blonde'],
                ],
            ],
            'vault_id' => 'vault_default',
        ];

        $input = UpsertCharacterProfileInput::fromArray($inputData);

        // Convert the input DTO back to an array structure expected by the repository
        $profile = [
            'id' => $input->id,
            'name' => $input->name,
            'context_id' => $input->context_id,
            'tags' => $input->tags,
            'traits' => $input->traits,
            'vault_id' => $input->vault_id,
        ];

        $result = $repository->upsertCharacterProfile($profile);

        $this->assertIsArray($result);
        $this->assertEquals('new_char', $result['characterId']);
        $this->assertArrayHasKey('stats', $result);
        $this->assertEquals(1, $result['stats']['character_upserted']);

        $character = DB::table('avatars')->where('id', 'new_char')->first();
        $this->assertNotNull($character);
        $this->assertEquals('New Avatar', $character->name);
        $this->assertEquals('vault_default', $character->vault_id);

        // Verify public_facade
        $this->assertNotNull($character->public_facade);
        $facade = json_decode($character->public_facade, true);
        $this->assertEquals('appearance', $facade[0]['key']);
        $this->assertEquals('Tall', $facade[0]['bullets'][0]['body']);

        // Verify legacy character_bullets (sync)
        if (DB::getSchemaBuilder()->hasTable('character_bullets')) {
            $bullets = DB::table('character_bullets')->where('character_id', 'new_char')->where('trait_key', 'appearance')->orderBy('sort_order')->get();
            $this->assertCount(2, $bullets);
            $this->assertEquals('Tall', $bullets[0]->content);
            $this->assertEquals('Blonde', $bullets[1]->content);
        }
    }
}

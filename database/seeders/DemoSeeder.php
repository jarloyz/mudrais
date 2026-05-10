<?php

namespace Database\Seeders;

use App\Domains\Matchmaking\Enums\MutatorStorageMode;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\ArchetypeMutator;
use App\Domains\Matchmaking\Models\ArchetypePrompt;
use App\Models\AiPromptTemplate;
use App\Models\AiProvider;
use App\Models\GameItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\UserProfileService;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRolesAndPermissions();
        $this->seedAdminUser();
        $this->seedAiProvider();
        $this->seedAiPromptTemplates();
        $this->seedGameItems();
        $this->seedArchetype();

        $this->command->info('✓ DemoSeeder completado.');
    }

    // ── 1. Roles y permisos ────────────────────────────────────────────────────

    private function seedRolesAndPermissions(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'access_filament_panel',
            'manage_users',
            'view_users',
            'manage_roles',
            'manage_players',
            'ban_players',
            'view_players',
            'manage_content',
            'view_content',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
        $viewer->syncPermissions(['access_filament_panel', 'view_content', 'view_players']);

        $moderator = Role::firstOrCreate(['name' => 'moderator', 'guard_name' => 'web']);
        $moderator->syncPermissions(['access_filament_panel', 'ban_players', 'manage_players', 'view_players', 'view_content']);

        $gameMaster = Role::firstOrCreate(['name' => 'game_master', 'guard_name' => 'web']);
        $gameMaster->syncPermissions(['access_filament_panel', 'manage_content', 'view_content', 'view_players']);

        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin->syncPermissions(Permission::all());

        $this->command->info('✓ Roles y permisos.');
    }

    // ── 2. Admin user ──────────────────────────────────────────────────────────

    private function seedAdminUser(): void
    {
        $user = User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@historia.local')],
            [
                'name'              => 'Administrador',
                'password'          => env('ADMIN_PASSWORD', 'changeme'),
                'email_verified_at' => now(),
            ]
        );

        if (! $user->hasRole('super_admin')) {
            $user->assignRole('super_admin');
        }

        UserProfileService::ensureProfile($user);

        $this->command->info('✓ Admin user.');
    }

    // ── 3. AI Provider ─────────────────────────────────────────────────────────

    private function seedAiProvider(): void
    {
        AiProvider::firstOrCreate(
            ['slug' => 'openrouter'],
            [
                'name'        => 'OpenRouter',
                'driver'      => 'openai_compatible',
                'base_url'    => 'https://openrouter.ai/api/v1',
                'api_key'     => config('historia.ai.openrouter.api_key'),
                'description' => 'OpenRouter — acceso a múltiples modelos vía API unificada.',
            ]
        );

        $this->command->info('✓ AI Provider (OpenRouter).');
    }

    // ── 4. AI Prompt Templates (base genérica del pipeline) ────────────────────

    private function seedAiPromptTemplates(): void
    {
        $templates = [
            [
                'key'         => 'player_profile_base',
                'description' => 'Template base del optimizer de perfil de jugador. Soporta {archetype_prompt_injection} y {user_soft_data_json}.',
                'body'        => <<<'PROMPT'
You are a Semantic Data Optimizer for a universal vector-based (RAG) matching engine.

Input: A JSON array of clean, factual POSITIVE preferences, traits, or requirements (already translated to English).

OBJECTIVE:
Analyze the array and abstract it into a single, highly dense English paragraph optimized purely for semantic similarity search.

STRICT GLOBAL RULES:
1. RAW TEXT ONLY: Output exclusively the final flowing paragraph. No markdown, no preambles.
2. POSITIVE AFFINITIES ONLY: Focus entirely on what the user actively seeks, offers, or prefers. Translate any negative logic into positive structural focuses.
3. FACTUAL SEMANTIC EXPANSION: Use ONLY the facts provided. Expand using standard terminology relevant to the domain.
4. FORMATTING: Single flowing paragraph. No headers, no bullets, no line breaks.
5. NO INTRODUCTIONS: Do NOT start with "Here is", "The user", or "This profile".

{archetype_prompt_injection}
PROMPT,
            ],
            [
                'key'         => 'vault_base',
                'description' => 'Template base del optimizer de Vault. Retorna JSON estructurado.',
                'body'        => <<<'PROMPT'
You are a Vault Semantics Engine for a vector-based (RAG) hierarchical roleplay matchmaking system.

Input: JSON {"name":"...","description":"..."} (may be in Spanish or English).

STRICT GLOBAL RULES:
1. RAW JSON ONLY: Output exclusively a valid JSON object. No markdown, no prose.
2. NO NEGATIVE LOGIC: Translate restrictions into positive structural focuses.
3. NO PROSE OR FILLER: Condense only what is explicitly present.

{archetype_prompt_injection}

EXPECTED JSON SCHEMA:
{
  "name_es": "Clean evocative Spanish name (2-4 words, title case)",
  "name_en": "English translation (2-4 words, title case)",
  "optimized_text_en": "Highly dense, declarative text string",
  "semantic_tag_query": "Dense descriptive string (10-25 words), comma-separated concepts"
}
PROMPT,
            ],
            [
                'key'         => 'archetype_base',
                'description' => 'Template base del optimizer de Arquetipo. Retorna JSON estructurado.',
                'body'        => <<<'PROMPT'
You are an Archetype Semantics Engine for a vector-based (RAG) hierarchical roleplay matchmaking system.

Input: JSON {"name":"...","text":"..."} (may be in Spanish or English).

STRICT GLOBAL RULES:
1. RAW JSON ONLY: Output exclusively a valid JSON object. No markdown, no prose.
2. NO NEGATIVE LOGIC: Translate restrictions into positive structural focuses.
3. ANONYMITY: Do NOT include the archetype name in "optimized_text_en".

EXPECTED JSON SCHEMA:
{
  "name_es": "Clean evocative Spanish name (2-4 words, title case)",
  "name_en": "English translation (2-4 words, title case)",
  "optimized_text_en": "Format: ROLE: [...] | DOMAIN: [...] | EXECUTION: [...] | ROUTING: [...]",
  "semantic_tag_query": "Dense descriptive string (10-25 words), comma-separated concepts"
}
PROMPT,
            ],
        ];

        foreach ($templates as $data) {
            AiPromptTemplate::firstOrCreate(
                ['key' => $data['key']],
                ['description' => $data['description'], 'body' => $data['body']]
            );
        }

        $this->command->info('✓ AI Prompt Templates (3 base templates).');
    }

    // ── 5. Game Items ──────────────────────────────────────────────────────────

    private function seedGameItems(): void
    {
        GameItem::updateOrCreate(
            ['key' => 'registro_edit'],
            [
                'name'         => 'Edición de Perfil',
                'description'  => 'Permite editar tu ficha de jugador en MUDRAIS.',
                'type'         => 'action_cost',
                'coin_delta'   => -50,
                'energy_delta' => 0,
                'is_active'    => true,
            ]
        );

        $this->command->info('✓ Game Items.');
    }

    // ── 6. Archetype: text_based_roleplay_v1 ──────────────────────────────────

    private function seedArchetype(): void
    {
        $archetype = Archetype::updateOrCreate(
            ['qdrant_vector_name' => 'text_based_roleplay_v1'],
            ['name' => 'Text-Based Roleplay']
        );

        // Gatekeeper: extrae datos estructurados del perfil en texto libre
        ArchetypePrompt::updateOrCreate(
            ['archetype_id' => $archetype->id, 'agent_type' => 'gatekeeper'],
            ['system_prompt' => $this->gatekeeperPrompt()]
        );

        // Optimizer: genera el párrafo semántico para el vector del jugador
        ArchetypePrompt::updateOrCreate(
            ['archetype_id' => $archetype->id, 'agent_type' => 'optimizer'],
            ['system_prompt' => $this->optimizerPrompt()]
        );

        $this->command->info('✓ Archetype "Text-Based Roleplay" + prompts.');

        // Mutadores de registro (/registro)
        $this->seedRegistrationMutators($archetype);

        // Entity types + sus mutadores
        $this->seedCharacterEntityType($archetype);
        $this->seedSessionSearchEntityType($archetype);
    }

    // ── 6a. Registro mutadores ─────────────────────────────────────────────────

    private function seedRegistrationMutators(Archetype $archetype): void
    {
        $fields = [
            [
                'field_key'         => 'red_lines',
                'field_label'       => 'Absolute Limits',
                'field_placeholder' => 'Forbidden topics. You will NEVER see games with these.',
                'field_type'        => 'text_long',
                'storage_mode'      => MutatorStorageMode::RAW,
                'is_required'       => false,
                'sort_order'        => 0,
            ],
            [
                'field_key'         => 'yellow_lines',
                'field_label'       => 'Soft Limits',
                'field_placeholder' => 'Max 10, from LEAST to MOST unpleasant (comma-separated).',
                'field_type'        => 'text_long',
                'storage_mode'      => MutatorStorageMode::RAW,
                'is_required'       => false,
                'sort_order'        => 1,
            ],
            [
                'field_key'         => 'preferences',
                'field_label'       => 'Favorite Genres',
                'field_placeholder' => 'Max 10, from MOST to LEAST preferred (comma-separated).',
                'field_type'        => 'text_long',
                'storage_mode'      => MutatorStorageMode::SEMANTIC,
                'is_required'       => true,
                'sort_order'        => 2,
            ],
            [
                'field_key'         => 'style',
                'field_label'       => 'Your Narrative Style',
                'field_placeholder' => 'Describe your engagement and vibe (3rd person, drama, etc).',
                'field_type'        => 'text_long',
                'storage_mode'      => MutatorStorageMode::SEMANTIC,
                'is_required'       => true,
                'sort_order'        => 3,
            ],
        ];

        foreach ($fields as $field) {
            ArchetypeMutator::updateOrCreate(
                [
                    'archetype_id' => $archetype->id,
                    'context'      => 'registration',
                    'field_key'    => $field['field_key'],
                ],
                $field
            );
        }

        $this->command->info('✓ Registro mutadores (red_lines, yellow_lines, preferences, style).');
    }

    // ── 6b. Entity type: character (avatar) ────────────────────────────────────

    private function seedCharacterEntityType(Archetype $archetype): void
    {
        $entityType = ArchetypeEntityType::updateOrCreate(
            ['archetype_id' => $archetype->id, 'type_key' => 'character'],
            [
                'entity'      => 'avatar',
                'type_label'  => 'My Character',
                'description' => 'Fictional character for roleplay: genre, personality and backstory.',
                'system_prompt' => $this->characterOptimizerPrompt(),
                'is_active'   => true,
                'sort_order'  => 1,
            ]
        );

        $fields = [
            [
                'field_key'         => 'genre',
                'field_label'       => 'Genre / Setting',
                'field_placeholder' => 'Fantasy, Sci-Fi, Horror, Modern, Historical…',
                'field_type'        => 'text_short',
                'storage_mode'      => MutatorStorageMode::BOTH,
                'is_required'       => true,
                'sort_order'        => 1,
            ],
            [
                'field_key'         => 'personality',
                'field_label'       => 'Personality & Traits',
                'field_placeholder' => 'Key personality traits, motivations and quirks.',
                'field_type'        => 'text_long',
                'storage_mode'      => MutatorStorageMode::SEMANTIC,
                'is_required'       => true,
                'sort_order'        => 2,
            ],
            [
                'field_key'         => 'backstory',
                'field_label'       => 'Backstory',
                'field_placeholder' => 'Brief origin, relevant history and current situation.',
                'field_type'        => 'text_long',
                'storage_mode'      => MutatorStorageMode::SEMANTIC,
                'is_required'       => false,
                'sort_order'        => 3,
            ],
        ];

        foreach ($fields as $field) {
            ArchetypeMutator::updateOrCreate(
                [
                    'archetype_id'             => $archetype->id,
                    'archetype_entity_type_id' => $entityType->id,
                    'context'                  => 'avatar_context',
                    'field_key'                => $field['field_key'],
                ],
                $field
            );
        }

        $this->command->info('✓ Entity type "character" + mutadores.');
    }

    // ── 6c. Entity type: session_search (activity) ─────────────────────────────

    private function seedSessionSearchEntityType(Archetype $archetype): void
    {
        $entityType = ArchetypeEntityType::updateOrCreate(
            ['archetype_id' => $archetype->id, 'type_key' => 'session_search'],
            [
                'entity'        => 'activity',
                'type_label'    => 'Looking for Session',
                'description'   => 'Post to find roleplay partners for a session or campaign.',
                'system_prompt' => $this->sessionSearchOptimizerPrompt(),
                'is_active'     => true,
                'sort_order'    => 1,
            ]
        );

        $fields = [
            [
                'field_key'         => 'session_type',
                'field_label'       => 'Session Type',
                'field_placeholder' => 'What kind of session are you looking for?',
                'field_type'        => 'select',
                'storage_mode'      => MutatorStorageMode::BOTH,
                'is_required'       => true,
                'sort_order'        => 1,
                'options'           => [
                    'placeholder' => 'Select session type…',
                    'min_values'  => 1,
                    'max_values'  => 2,
                    'items'       => [
                        ['label' => 'One-Shot',  'value' => 'one_shot',  'description' => 'Single self-contained session'],
                        ['label' => 'Campaign',  'value' => 'campaign',  'description' => 'Long-term ongoing story'],
                        ['label' => 'Open World','value' => 'open_world','description' => 'Sandbox, no fixed end'],
                        ['label' => 'PBP',       'value' => 'pbp',       'description' => 'Play-by-post, async writing'],
                    ],
                ],
            ],
            [
                'field_key'         => 'themes',
                'field_label'       => 'Themes & Genres',
                'field_placeholder' => 'Describe the themes, tone and genres you are looking for.',
                'field_type'        => 'text_long',
                'storage_mode'      => MutatorStorageMode::SEMANTIC,
                'is_required'       => true,
                'sort_order'        => 2,
            ],
            [
                'field_key'         => 'frequency',
                'field_label'       => 'Session Frequency',
                'field_placeholder' => 'How often do you plan to play?',
                'field_type'        => 'select',
                'storage_mode'      => MutatorStorageMode::RAW,
                'is_required'       => false,
                'sort_order'        => 3,
                'options'           => [
                    'placeholder' => 'Select frequency…',
                    'min_values'  => 1,
                    'max_values'  => 1,
                    'items'       => [
                        ['label' => 'Daily',    'value' => 'daily',    'description' => 'Every day'],
                        ['label' => 'Weekly',   'value' => 'weekly',   'description' => 'Once a week'],
                        ['label' => 'Biweekly', 'value' => 'biweekly', 'description' => 'Every two weeks'],
                        ['label' => 'Casual',   'value' => 'casual',   'description' => 'No fixed schedule'],
                    ],
                ],
            ],
            [
                'field_key'         => 'filter_available_only',
                'field_label'       => 'Available Players Only',
                'field_placeholder' => 'Only show players currently available for new activities.',
                'field_type'        => 'select',
                'storage_mode'      => MutatorStorageMode::RAW,
                'is_required'       => false,
                'sort_order'        => 99,
                'options'           => [
                    'placeholder' => 'Filter by availability?',
                    'min_values'  => 0,
                    'max_values'  => 1,
                    'items'       => [
                        ['label' => 'Yes — available players only', 'value' => 'true',  'description' => 'Only show profiles marked as available.'],
                        ['label' => 'No — show all players',        'value' => 'false', 'description' => 'Include all profiles regardless of availability.'],
                    ],
                ],
            ],
        ];

        foreach ($fields as $field) {
            $options = $field['options'] ?? null;
            unset($field['options']);

            $data = $field;
            if ($options !== null) {
                $data['options'] = $options;
            }

            ArchetypeMutator::updateOrCreate(
                [
                    'archetype_id'             => $archetype->id,
                    'archetype_entity_type_id' => $entityType->id,
                    'context'                  => 'activities_vibe',
                    'field_key'                => $field['field_key'],
                ],
                $data
            );
        }

        $this->command->info('✓ Entity type "session_search" + mutadores.');
    }

    // ── Prompts ────────────────────────────────────────────────────────────────

    private function gatekeeperPrompt(): string
    {
        return <<<'PROMPT'
You are a data extractor for text-based roleplay player profiles. You receive a partial JSON with already-extracted fields and the original profile text.

Your only task: complete fields whose value is null or empty array [], using the original text.

JSON FIELDS:
- age: integer (years)
- nationality: string
- experience_level: one of [Novice, Veteran, Master]
- schedule: object {description: string, timezone: string (e.g. "UTC-6")}
- verbosity: string (e.g. "High", "Medium", "Low")
- red_lines: array of strings with hard forbidden topics — include ALL that appear
- yellow_lines: array of strings with topics the player tolerates but dislikes — include ALL that appear
- affinities: array of strings ordered by priority (genres, tropes, writing style)
- raw_profile: full text of the narrative style section

RULES:
1. Reply ONLY with the complete JSON. No additional text, no markdown.
2. Do NOT modify fields that already have a value.
3. If a field cannot be extracted from the text, leave it null.
4. ALL text values must be in English — translate from the source language if needed.
PROMPT;
    }

    private function optimizerPrompt(): string
    {
        return <<<'PROMPT'
You are a Semantic Data Optimizer for a Text-Based Roleplay Matchmaking Engine.

Your input is a JSON array of clean, factual POSITIVE style preferences already translated to English.
Your task: rewrite these facts as a single dense English paragraph optimized for semantic vector embedding.

STRICT RULES:
1. Focus ONLY on positive affinities. Build a cohesive profile of what the player actively seeks in text roleplay.
2. Use ONLY the facts provided — do NOT invent or assume any preference not present in the array.
3. Incorporate relevant dimensions naturally: POV, Tone, Pacing, Genre, Character Development, Narrative Style, Writing Quality.
4. Use standard literary/RPG terminology to expand each fact semantically.
5. Output: a single flowing paragraph. No headers, no bullets, no markdown.
6. Do NOT start with "Here is", "The player", or any introduction. Start directly with the content.
PROMPT;
    }

    private function characterOptimizerPrompt(): string
    {
        return <<<'PROMPT'
You are a Semantic Data Optimizer for a vector-based (RAG) text roleplay matchmaking system.
Your task is to extract a semantic fingerprint from a fictional character profile.

## Character Data
{context_data_json}

## Roleplay Domain Guidelines
{archetype_prompt_injection}

---

STRICT INSTRUCTIONS:

1. RAW JSON ONLY: Your final output must be exclusively a valid JSON object.
   Do not include markdown formatting (```json), explanations, or preambles.

2. NO NEGATIVE LOGIC: NEVER include exclusions.
   Translate all restrictions into positive structural focuses.

EXPECTED JSON SCHEMA:
{
  "optimized_text_en": "Dense English paragraph (60-150 words) synthesizing character genre, personality archetypes, backstory themes, and roleplay tone. ONLY positive attributes.",
  "semantic_tag_query": "15-25 comma-separated terms: genre, tropes, character archetypes, narrative tone, themes"
}
PROMPT;
    }

    private function sessionSearchOptimizerPrompt(): string
    {
        return <<<'PROMPT'
You are a Semantic Data Optimizer for a vector-based (RAG) text roleplay matchmaking system.
Your task is to extract a semantic fingerprint from a session search post to match
compatible players via cosine similarity.

## Session Request
{user_soft_data_json}

## Roleplay Domain Guidelines
{archetype_prompt_injection}

---

STRICT INSTRUCTIONS:

1. RAW JSON ONLY: Your final output must be exclusively a valid JSON object.
   Do not include markdown formatting (```json), explanations, or preambles.

2. NO NEGATIVE LOGIC: NEVER include exclusions. Translate all constraints into positive structural focuses.

EXPECTED JSON SCHEMA:
{
  "optimized_text_en": "Dense English paragraph (60-150 words) describing the sought session type, themes, tone, frequency and collaboration style. ONLY positive attributes.",
  "semantic_tag_query": "15-25 comma-separated terms: session format, genre, tone, themes, roleplay style"
}
PROMPT;
    }
}

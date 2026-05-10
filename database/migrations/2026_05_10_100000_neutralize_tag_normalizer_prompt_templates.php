<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ai_prompt_templates')
            ->where('key', 'tag_normalizer_verify')
            ->update(['body' => $this->verifyBody()]);

        DB::table('ai_prompt_templates')
            ->where('key', 'tag_normalizer_create')
            ->update(['body' => $this->createBody()]);
    }

    public function down(): void
    {
        DB::table('ai_prompt_templates')
            ->where('key', 'tag_normalizer_verify')
            ->update(['body' => <<<'PROMPT'
A tabletop RPG player wrote (may be in any language): "{raw_input}"

The most similar existing tags are:
{candidates_list}

Does this input match any of the candidates? Consider the meaning, not just the words — translate mentally if needed.
Reply ONLY with the exact slug of the best match.
If none match correctly, reply: NUEVO
No explanation.
PROMPT]);

        DB::table('ai_prompt_templates')
            ->where('key', 'tag_normalizer_create')
            ->update(['body' => <<<'PROMPT'
A tabletop RPG player wrote: "{raw_input}"

No similar tag exists in the system. Create a new canonical tag.

RULES:
- slug and name MUST be in English, even if the input is in another language. Translate if needed.
- slug: snake_case, max 30 chars, English words only.
- name: short readable label in English (2-4 words).
- description: technical English description 15-40 words for semantic search.

Respond ONLY with this JSON (no markdown, no extra text):
{"slug":"english_snake_case","name":"English Label","description":"Technical English description for semantic search."}
PROMPT]);
    }

    private function verifyBody(): string
    {
        return <<<'PROMPT'
A user wrote (may be in any language): "{raw_input}"

The most similar existing tags are:
{candidates_list}

Does this input match any of the candidates? Consider the meaning, not just the words — translate mentally if needed.
Reply ONLY with the exact slug of the best match.
If none match correctly, reply: NUEVO
No explanation.
PROMPT;
    }

    private function createBody(): string
    {
        return <<<'PROMPT'
A user wrote: "{raw_input}"

No similar tag exists in the system. Create a new canonical tag.

RULES:
- slug and name MUST be in English, even if the input is in another language. Translate if needed.
- slug: snake_case, max 30 chars, English words only.
- name: short readable label in English (2-4 words).
- description: technical English description 15-40 words for semantic search.

Respond ONLY with this JSON (no markdown, no extra text):
{"slug":"english_snake_case","name":"English Label","description":"Technical English description for semantic search."}
PROMPT;
    }
};

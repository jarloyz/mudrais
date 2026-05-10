<?php

namespace Tests\Unit\Jobs;

use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Jobs\Discord\ProcessBuscarJob;
use App\Jobs\Discord\ProcessFichaModalJob;
use App\Jobs\Discord\ProcessRegistroStep2Job;
use App\Jobs\Discord\ProcessStatusJob;
use App\Jobs\IndexLoreEntryJob;
use App\Jobs\IndexPlayerStyleJob;
use App\Jobs\NormalizeAvatarTagsJob;
use App\Jobs\NormalizePlayerTagsJob;
use App\Jobs\NormalizeSingleTagJob;
use App\Jobs\SyncActivityHubStatusJob;
use App\Jobs\SyncPlayerQdrantGuildsJob;
use Tests\TestCase;

/**
 * Verifica que cada Job esté configurado para ejecutarse en la cola correcta.
 *
 * Queues del sistema:
 *   default → Discord modal submissions, interacciones de usuario
 *   high    → Búsquedas y respuestas rápidas (< 3s Discord timeout)
 *   index   → Pipeline LLM de indexación (IndexAvatarJob, IndexVaultJob, etc.)
 *   tags    → Normalización de tags (NormalizeSingleTagJob y orchestrators)
 *   sync    → Mantenimiento background (Sync*)
 */
class QueueChannelTest extends TestCase
{
    // ── default ───────────────────────────────────────────────────────────────

    public function test_process_registro_step2_job_uses_default_queue(): void
    {
        $job = new ProcessRegistroStep2Job('discord_id', [], 'token');
        $this->assertSame('default', $job->queue);
    }

    public function test_process_ficha_modal_job_uses_default_queue(): void
    {
        $job = new ProcessFichaModalJob('discord_id', 'profile_text', 'token');
        $this->assertSame('default', $job->queue);
    }

    // ── high ──────────────────────────────────────────────────────────────────

    public function test_process_status_job_uses_high_queue(): void
    {
        $job = new ProcessStatusJob('discord_id', 'token');
        $this->assertSame('high', $job->queue);
    }

    public function test_process_buscar_job_uses_high_queue(): void
    {
        $job = new ProcessBuscarJob('token', 'discord_id');
        $this->assertSame('high', $job->queue);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_player_style_job_uses_index_queue(): void
    {
        $job = new IndexPlayerStyleJob(1);
        $this->assertSame('index', $job->queue);
    }

    public function test_index_lore_entry_job_uses_index_queue(): void
    {
        $job = new IndexLoreEntryJob(1);
        $this->assertSame('index', $job->queue);
    }

    // ── tags ──────────────────────────────────────────────────────────────────

    public function test_normalize_player_tags_job_uses_tags_queue(): void
    {
        $job = new NormalizePlayerTagsJob(new PlayerArchetypeProfile(), []);
        $this->assertSame('tags', $job->queue);
    }

    public function test_normalize_avatar_tags_job_uses_tags_queue(): void
    {
        $job = new NormalizeAvatarTagsJob('some-avatar-id', ['term1', 'term2']);
        $this->assertSame('tags', $job->queue);
    }

    public function test_normalize_single_tag_job_uses_tags_queue(): void
    {
        $job = new NormalizeSingleTagJob(
            avatarId:   'some-avatar-id',
            profileId:  null,
            term:       'epic fantasy',
            tagContext: 'semantic',
        );
        $this->assertSame('tags', $job->queue);
    }

    // ── sync ──────────────────────────────────────────────────────────────────

    public function test_sync_activity_hub_status_job_uses_sync_queue(): void
    {
        $job = new SyncActivityHubStatusJob('some-activity-id');
        $this->assertSame('sync', $job->queue);
    }

    public function test_sync_player_qdrant_guilds_job_uses_sync_queue(): void
    {
        $job = new SyncPlayerQdrantGuildsJob('some-profile-id');
        $this->assertSame('sync', $job->queue);
    }
}

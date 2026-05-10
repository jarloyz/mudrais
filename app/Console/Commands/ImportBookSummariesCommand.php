<?php

namespace App\Console\Commands;

use App\Domains\Community\Models\Guild;
use App\Domains\Community\Models\Player;
use App\Domains\Matchmaking\Models\Archetype;
use App\Domains\Matchmaking\Models\ArchetypeEntityType;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Narrative\Models\Avatar;
use App\Domains\Narrative\Models\Vault;
use App\Jobs\IndexAvatarJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Importa libros desde datasets/booksummaries.txt como Avatars del archetype Libros.
 * El proceso de indexación (optimizer → embedding → Qdrant → tags) lo maneja IndexAvatarJob,
 * exactamente igual que cuando llega un contexto desde Discord.
 *
 * Uso:
 *   sail artisan books:import-summaries
 *   sail artisan books:import-summaries --limit=1 --dry-run
 */
class ImportBookSummariesCommand extends Command
{
    private const BASE_DISCORD_GUILD_ID  = '1493874482404790303';
    private const BASE_DISCORD_PLAYER_ID = '220800677218091008';

    protected $signature = 'books:import-summaries
                            {--path= : Ruta al archivo booksummaries.txt}
                            {--limit=0 : Límite de libros a importar (0 = todos)}
                            {--vault-id= : UUID del vault destino (requerido si el guild tiene múltiples vaults)}
                            {--dry-run : Muestra el primer registro sin guardar ni despachar jobs}';

    protected $description = 'Importa el dataset CMU Book Summary como Avatars y los encola en IndexAvatarJob';

    public function handle(): int
    {
        Log::info('[ImportBookSummariesCommand] Inicio', [
            'dry_run' => $this->option('dry-run'),
            'limit'   => $this->option('limit'),
        ]);

        $path = $this->option('path') ?: base_path('datasets/booksummaries.txt');

        if (! file_exists($path)) {
            $this->error("Archivo no encontrado: {$path}");
            return self::FAILURE;
        }

        $entityType = $this->resolveBookEntityType();
        if ($entityType === null) {
            return self::FAILURE;
        }

        ['vault_id' => $vaultId, 'owner_profile_id' => $ownerProfileId] =
            $this->resolveBaseEntities($entityType);

        $limit  = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("No se pudo abrir el archivo.");
            return self::FAILURE;
        }

        $total = $imported = $skipped = 0;

        $lineCount = 0;
        while (! feof($handle)) {
            if (fgets($handle) !== false) {
                $lineCount++;
            }
        }
        rewind($handle);

        $targetCount = ($limit > 0) ? min($limit, $lineCount) : $lineCount;
        $bar = $this->output->createProgressBar($targetCount);
        $bar->start();

        while (! feof($handle)) {
            $line = fgets($handle);
            if ($line === false || trim($line) === '') {
                continue;
            }

            if ($limit > 0 && $total >= $limit) {
                break;
            }

            $total++;
            $bar->advance();

            $book = $this->parseLine($line);
            if ($book === null) {
                $skipped++;
                continue;
            }

            if (Avatar::where('name', $book['title'])
                ->where('archetype_entity_type_id', $entityType->id)
                ->exists()
            ) {
                $skipped++;
                Log::debug('[ImportBookSummariesCommand] Duplicado omitido', ['title' => $book['title']]);
                continue;
            }

            $contentRaw = $this->buildContentRaw($book);

            if ($dryRun) {
                $bar->clear();
                $this->info("\n[DRY-RUN] Registro que se crearía:");
                $this->line("  name:             {$book['title']}");
                $this->line("  entity_type_id:   {$entityType->id} ({$entityType->type_key})");
                $this->line("  vault_id:         " . ($vaultId ?? '(ninguno)'));
                $this->line("  owner_profile_id: " . ($ownerProfileId ?? '(ninguno)'));
                $this->line("  content_raw:");
                foreach ($contentRaw as $key => $value) {
                    $display = is_string($value) ? mb_substr($value, 0, 80) . (mb_strlen($value) > 80 ? '…' : '') : json_encode($value);
                    $this->line("    {$key}: {$display}");
                }
                $this->line("  → IndexAvatarJob sería despachado tras guardar.");
                break;
            }

            $avatar = Avatar::create([
                'name'                     => $book['title'],
                'archetype_entity_type_id' => $entityType->id,
                'vault_id'                 => $vaultId,
                'owner_profile_id'         => $ownerProfileId,
                'content_raw'              => $contentRaw,
                'is_lfg'                   => false,
                'is_hub_indexed'           => false,
            ]);

            IndexAvatarJob::dispatch($avatar->id);

            Log::info('[ImportBookSummariesCommand] Avatar creado y job despachado', [
                'avatar_id' => $avatar->id,
                'title'     => $book['title'],
            ]);

            $imported++;
        }

        fclose($handle);
        $bar->finish();
        $this->newLine(2);

        if (! $dryRun) {
            $this->table(
                ['Total leídas', 'Avatars creados', 'Omitidas'],
                [[$total, $imported, $skipped]]
            );
        }

        Log::info('[ImportBookSummariesCommand] Finalizado', compact('total', 'imported', 'skipped', 'dryRun'));

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function resolveBookEntityType(): ?ArchetypeEntityType
    {
        $archetype = Archetype::whereIn('name', ['Libros', 'Semantic Reading'])->first();

        if ($archetype === null) {
            $this->error('Archetype de libros no encontrado. Ejecuta ArchetypeSeeder primero.');
            Log::error('[ImportBookSummariesCommand] Archetype no encontrado');
            return null;
        }

        $entityType = ArchetypeEntityType::where('archetype_id', $archetype->id)
            ->where('type_key', 'book')
            ->first();

        if ($entityType === null) {
            $this->error("Entity type 'book' no encontrado en el archetype '{$archetype->name}'.");
            Log::error('[ImportBookSummariesCommand] Entity type book no encontrado', [
                'archetype_id' => $archetype->id,
            ]);
            return null;
        }

        $this->info("Archetype: {$archetype->name} | Entity type: {$entityType->type_label}");
        return $entityType;
    }

    /**
     * Resuelve vault y owner_profile_id del guild/player base para imports masivos.
     * El entity type de libros tiene exclude_own=false en matching_filters, por lo que
     * el filtro de MatchmakingService no bloqueará al creador en los resultados.
     *
     * @return array{vault_id: string|null, owner_profile_id: string|null}
     */
    private function resolveBaseEntities(ArchetypeEntityType $entityType): array
    {
        $result = ['vault_id' => null, 'owner_profile_id' => null];

        $guild = Guild::where('discord_guild_id', self::BASE_DISCORD_GUILD_ID)->first();

        if ($guild === null) {
            $this->warn('Guild base no encontrado — los avatars se crearán sin vault ni guild_id en Qdrant.');
            Log::warning('[ImportBookSummariesCommand] Guild base no encontrado', [
                'discord_guild_id' => self::BASE_DISCORD_GUILD_ID,
            ]);
        } else {
            $explicitVaultId = $this->option('vault-id');
            if ($explicitVaultId) {
                $vault = Vault::find($explicitVaultId);
            } else {
                $vaultCount = Vault::where('guild_id', $guild->id)->count();
                if ($vaultCount > 1) {
                    $this->error("El guild tiene {$vaultCount} vaults — usa --vault-id=<uuid> para especificar cuál.");
                    Log::error('[ImportBookSummariesCommand] Guild con múltiples vaults sin --vault-id', [
                        'guild_id'    => $guild->id,
                        'vault_count' => $vaultCount,
                    ]);
                    return $result;
                }
                $vault = Vault::where('guild_id', $guild->id)->first();
            }
            if ($vault) {
                $result['vault_id'] = $vault->id;
                $this->info("Vault resuelto: {$vault->name} ({$vault->id})");
            } else {
                $this->warn("Vault no encontrado — los avatars irán sin vault.");
                Log::warning('[ImportBookSummariesCommand] Vault no encontrado', ['guild_id' => $guild->id]);
            }
        }

        $player = Player::where('discord_id', self::BASE_DISCORD_PLAYER_ID)->first();

        if ($player === null) {
            $this->warn('Player base no encontrado — los avatars se crearán sin owner_profile_id.');
            Log::warning('[ImportBookSummariesCommand] Player base no encontrado', [
                'discord_player_id' => self::BASE_DISCORD_PLAYER_ID,
            ]);
        } else {
            $profile = PlayerArchetypeProfile::where('player_id', $player->id)
                ->where('archetype_id', $entityType->archetype_id)
                ->first();

            if ($profile) {
                $result['owner_profile_id'] = $profile->id;
                $this->info("Owner profile resuelto: player {$player->username} → profile {$profile->id}");
            } else {
                $this->warn("Player encontrado pero sin perfil en este archetype — avatars sin owner.");
                Log::warning('[ImportBookSummariesCommand] Player sin perfil en archetype', [
                    'player_id'    => $player->id,
                    'archetype_id' => $entityType->archetype_id,
                ]);
            }
        }

        Log::info('[ImportBookSummariesCommand] Entidades base resueltas', $result);

        return $result;
    }

    /**
     * Parsea una línea tab-separated del dataset.
     * Columnas: wiki_id, freebase_id, title, author, pub_date, genres_json, summary
     *
     * @return array{title:string, author:string, pub_date:string, genres:array<string>, summary:string}|null
     */
    private function parseLine(string $line): ?array
    {
        $parts = explode("\t", rtrim($line, "\n\r"));
        if (count($parts) < 7) {
            return null;
        }

        [, , $title, $author, $pubDate, $genresJson, $summary] = $parts;

        $title   = trim($title);
        $summary = trim($summary);

        if ($title === '' || $summary === '') {
            return null;
        }

        $genresRaw = json_decode($genresJson, true);
        $genres    = is_array($genresRaw) ? array_values($genresRaw) : [];

        return [
            'title'    => $title,
            'author'   => trim($author),
            'pub_date' => $pubDate,
            'genres'   => $genres,
            'summary'  => $summary,
        ];
    }

    /**
     * @param  array{title:string, author:string, pub_date:string, genres:array<string>, summary:string} $book
     * @return array<string, mixed>
     */
    private function buildContentRaw(array $book): array
    {
        $year = $this->extractYear($book['pub_date']);

        return [
            'author'            => $book['author'] ?: null,
            'synopsis'          => $book['summary'],
            'themes_and_tropes' => $book['genres'] !== [] ? implode(', ', $book['genres']) : null,
            'publication_era'   => $this->derivePublicationEra($year),
            'publication_type'  => $this->derivePublicationType($year),
            'reading_languages' => 'English',
            'style'             => null,
            'content_warnings'  => null,
            'format_length'     => null,
        ];
    }

    private function extractYear(string $pubDate): ?int
    {
        if (preg_match('/^(\d{4})/', trim($pubDate), $m)) {
            return (int) $m[1];
        }
        return null;
    }

    private function derivePublicationEra(?int $year): ?string
    {
        if ($year === null) {
            return null;
        }
        return match (true) {
            $year < 1900 => '19th Century or Earlier',
            $year < 1940 => 'Early 20th Century (1900-1939)',
            $year < 1960 => 'Mid-20th Century (1940-1959)',
            $year < 1980 => 'Late 20th Century (1960-1979)',
            $year < 2000 => 'Late 20th Century (1980-1999)',
            default      => 'Contemporary (2000s+)',
        };
    }

    private function derivePublicationType(?int $year): ?string
    {
        if ($year === null) {
            return null;
        }
        return match (true) {
            $year < 1960 => 'clasico',
            $year < 1990 => 'tradicional',
            default      => 'contemporaneo',
        };
    }
}

<?php

namespace App\Console\Commands;

use App\Domains\Narrative\Models\Avatar;
use App\Enums\IndexingStatus;
use App\Jobs\IndexAvatarJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Re-encola avatars fallidos para reintento de indexación.
 *
 * Uso:
 *   sail artisan avatars:requeue-failed
 *   sail artisan avatars:requeue-failed --filter=max_tokens
 *   sail artisan avatars:requeue-failed --filter=max_tokens --limit=100
 *   sail artisan avatars:requeue-failed --dry-run
 */
class RequeueFailedAvatarsCommand extends Command
{
    protected $signature = 'avatars:requeue-failed
                            {--filter= : Fragmento de texto a buscar en index_error (ej: max_tokens, JSON inválido)}
                            {--limit=0 : Máximo de avatars a re-encolar (0 = todos)}
                            {--include-processing : Incluir también avatars en estado processing (huérfanos por crash)}
                            {--dry-run : Muestra los avatars sin re-encolar}';

    protected $description = 'Re-encola avatars con indexing_status=failed (o processing huérfanos) en IndexAvatarJob';

    public function handle(): int
    {
        Log::info('[RequeueFailedAvatarsCommand] Inicio', [
            'filter'  => $this->option('filter'),
            'limit'   => $this->option('limit'),
            'dry_run' => $this->option('dry-run'),
        ]);

        $statuses = [IndexingStatus::Failed];
        if ($this->option('include-processing')) {
            $statuses[] = IndexingStatus::Processing;
        }

        $query = Avatar::whereIn('indexing_status', $statuses);

        $filter = $this->option('filter');
        if (filled($filter)) {
            $query->where('index_error', 'like', "%{$filter}%");
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No hay avatars fallidos' . (filled($filter) ? " con filter=\"{$filter}\"" : '') . '.');
            return self::SUCCESS;
        }

        $limit  = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $statusLabel = count($statuses) > 1 ? 'failed + processing' : 'failed';
        $label = "status={$statusLabel}" . (filled($filter) ? ", filter=\"{$filter}\"" : '');
        $this->info("Avatars fallidos ({$label}): {$total}" . ($limit > 0 ? " — procesando máximo {$limit}" : ''));

        if ($dryRun) {
            $sample = (clone $query)->limit(10)->get(['id', 'name', 'index_error']);
            $this->table(
                ['ID', 'Nombre', 'Error (truncado)'],
                $sample->map(fn($a) => [
                    $a->id,
                    mb_substr($a->name ?? '', 0, 40),
                    mb_substr($a->index_error ?? '', 0, 80),
                ])->all()
            );
            if ($total > 10) {
                $this->line("  ... y " . ($total - 10) . " más.");
            }
            $this->warn('[DRY-RUN] Ningún job fue despachado.');
            return self::SUCCESS;
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $dispatched = 0;

        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, $total) : $total);
        $bar->start();

        $query->select('id')->chunkById(200, function ($avatars) use (&$dispatched, $bar) {
            foreach ($avatars as $avatar) {
                $avatar->update(['indexing_status' => IndexingStatus::Pending, 'index_error' => null]);
                IndexAvatarJob::dispatch($avatar->id);
                $dispatched++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Re-encolados', 'Total fallidos encontrados'],
            [[$dispatched, $total]]
        );

        Log::info('[RequeueFailedAvatarsCommand] Finalizado', [
            'dispatched' => $dispatched,
            'total_failed' => $total,
            'filter'     => $filter,
        ]);

        return self::SUCCESS;
    }
}

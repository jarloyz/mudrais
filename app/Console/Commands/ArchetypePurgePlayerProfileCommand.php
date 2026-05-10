<?php

namespace App\Console\Commands;

use App\Application\Services\QdrantService;
use App\Domains\Matchmaking\Models\PlayerArchetypeProfile;
use App\Domains\Narrative\Models\Avatar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArchetypePurgePlayerProfileCommand extends Command
{
    protected $signature = 'archetype:purge-profile
                            {profile_id : ID del PlayerArchetypeProfile a eliminar}
                            {--force : Omitir confirmación}';

    protected $description = 'Elimina un perfil de arquetipo, sus avatars asociados, participaciones y sus vectores en Qdrant.';

    public function handle(QdrantService $qdrant): int
    {
        $profileId = $this->argument('profile_id');
        $profile = PlayerArchetypeProfile::with(['player', 'archetype'])->find($profileId);

        if (!$profile) {
            $this->error("El perfil #{$profileId} no existe.");
            return self::FAILURE;
        }

        $avatars = Avatar::where('owner_profile_id', $profile->id)->get();

        $this->warn("Se eliminará:");
        $this->line("  · Perfil: #{$profile->id} [Arquetipo: {$profile->archetype->name}] [Player: {$profile->player->username}]");
        $this->line("  · Avatars asociados: {$avatars->count()}");
        $this->line("  · Vectores en Qdrant (Perfil + Avatars)");

        if (!$this->option('force') && !$this->confirm('¿Deseas continuar con la eliminación total?', false)) {
            $this->info('Operación cancelada.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($profile, $avatars, $qdrant) {
            // 1. Limpiar Qdrant
            $this->info("Limpiando Qdrant...");

            // Vector del Perfil
            if ($profile->qdrant_id) {
                $qdrant->deleteHubPoint($profile->qdrant_id);
                $this->info("  ✓ Vector del perfil eliminado");
            }

            // Vectores de Avatars
            foreach ($avatars as $avatar) {
                if ($avatar->avatar_hub_qdrant_id) {
                    $qdrant->deleteHubPoint($avatar->avatar_hub_qdrant_id);
                    $this->info("  ✓ Vector de avatar #{$avatar->id} eliminado");
                }
            }

            // 2. Limpiar SQL
            $this->info("Limpiando base de datos SQL...");

            // Borrar membresías en actividades de estos avatars
            foreach ($avatars as $avatar) {
                $count = DB::table('activity_members')->where('avatar_id', $avatar->id)->delete();
                if ($count > 0) {
                    $this->info("  ✓ {$count} participaciones de avatar #{$avatar->id} eliminadas");
                }
                $avatar->delete();
                $this->info("  ✓ Avatar #{$avatar->id} eliminado");
            }

            // Borrar participaciones directas del perfil (si las hay)
            $papMemberships = DB::table('activity_members')->where('player_archetype_profile_id', $profile->id)->delete();
            if ($papMemberships > 0) {
                $this->info("  ✓ {$papMemberships} participaciones directas del perfil eliminadas");
            }

            // Finalmente borrar el perfil
            $profile->delete();
            $this->info("  ✓ Perfil de arquetipo eliminado");
        });

        Log::info('[ArchetypePurgePlayerProfileCommand] Purga completada', [
            'profile_id' => $profileId,
            'avatars_count' => $avatars->count()
        ]);

        $this->info("\nLimpieza completada satisfactoriamente.");

        return self::SUCCESS;
    }
}

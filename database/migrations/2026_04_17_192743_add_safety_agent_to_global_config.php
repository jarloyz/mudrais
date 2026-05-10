<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SAFETY_MODEL = 'meta-llama/llama-3.1-8b-instruct:free';

    public function up(): void
    {
        $global = DB::table('agent_configs')->where('scope', 'global')->first();

        if (! $global) {
            return;
        }

        $settings = json_decode($global->settings_json, true) ?? [];

        // Only inject if not already set
        if (! isset($settings['agents']['safety'])) {
            $settings['agents']['safety'] = ['model' => self::SAFETY_MODEL];
            DB::table('agent_configs')
                ->where('scope', 'global')
                ->update(['settings_json' => json_encode($settings)]);
        }
    }

    public function down(): void
    {
        $global = DB::table('agent_configs')->where('scope', 'global')->first();

        if (! $global) {
            return;
        }

        $settings = json_decode($global->settings_json, true) ?? [];
        unset($settings['agents']['safety']);

        DB::table('agent_configs')
            ->where('scope', 'global')
            ->update(['settings_json' => json_encode($settings)]);
    }
};

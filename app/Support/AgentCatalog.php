<?php

namespace App\Support;

use App\Models\AgentConfig;
use App\Models\AiProvider;
use Illuminate\Support\Facades\Schema;

class AgentCatalog
{
    /**
     * Canonical agent list. These are the only agents the system uses.
     * Order determines display order in the settings UI.
     *
     * @var array<string, array{label:string, section:string, description:string}>
     */
    private const AGENTS = [
        'gatekeeper' => [
            'label' => 'Gatekeeper',
            'section' => 'Ingesta',
            'description' => 'Extrae datos de Discord y genera el JSON de perfil del jugador.',
        ],
        'safety' => [
            'label' => 'Safety',
            'section' => 'Ingesta',
            'description' => 'Clasifica texto libre del usuario como SAFE/UNSAFE antes de almacenarlo. Modelo ligero recomendado.',
        ],
        'embedding' => [
            'label' => 'Embedding',
            'section' => 'Ingesta',
            'description' => 'Traduce texto a vectores matemáticos para almacenamiento en Qdrant.',
        ],
        'optimizer' => [
            'label' => 'Optimizer',
            'section' => 'Ingesta',
            'description' => 'Inyector semántico: convierte hechos limpios de perfil en un párrafo denso optimizado para embedding. Modelo capaz recomendado.',
        ],
        'librarian' => [
            'label' => 'Librarian',
            'section' => 'Memoria',
            'description' => 'Busca en Qdrant el Lore relevante y los perfiles de jugadores compatibles.',
        ],
        'writer' => [
            'label' => 'Writer',
            'section' => 'Narrativa',
            'description' => 'Narra la partida y las consecuencias de las acciones del jugador.',
        ],
        'critic' => [
            'label' => 'Critic',
            'section' => 'Narrativa',
            'description' => 'Árbitro que bloquea trampas, god-mode y líneas rojas del jugador.',
        ],
    ];

    /**
     * @return array<int, array{
     *   key:string,
     *   label:string,
     *   model:string,
     *   enabled:bool,
     *   section:string,
     *   description:string
     * }>
     */
    public function all(): array
    {
        $dbModels = $this->loadDbModels();
        $catalog = [];

        foreach (self::AGENTS as $key => $meta) {
            $catalog[] = [
                'key' => $key,
                'label' => $meta['label'],
                'model' => $dbModels[$key] ?? '',
                'enabled' => true,
                'section' => $meta['section'],
                'description' => $meta['description'],
            ];
        }

        return $catalog;
    }

    /**
     * @return array<string, string>
     */
    private function loadDbModels(): array
    {
        if (! Schema::hasTable('agent_configs')) {
            return [];
        }

        $global = AgentConfig::query()->where('scope', 'global')->first();

        if (! $global) {
            return [];
        }

        $dbModels = [];
        $agents = $global->settings_json['agents'] ?? [];

        foreach ($agents as $key => $cfg) {
            if (is_array($cfg) && is_string($cfg['model'] ?? null) && trim($cfg['model']) !== '') {
                $dbModels[(string) $key] = trim($cfg['model']);
            }
        }

        return $dbModels;
    }

    /**
     * @return array<string, string>
     */
    public function modelMap(): array
    {
        $map = [];

        foreach ($this->all() as $agent) {
            $map[$agent['key']] = $agent['model'];
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    public function providers(): array
    {
        if (! Schema::hasTable('ai_providers')) {
            return ['openrouter', 'anthropic'];
        }

        return array_keys(AiProvider::slugOptions());
    }
}

<?php

namespace App\Application\Services;

use App\Models\CharacterInventory;
use App\Application\Contracts\AiChatGateway;
use App\Support\UserAiSettingsResolver;
use App\Application\Contracts\StructuredLogger;

class InventorySemanticFilter
{
    public function __construct(
        private readonly AiChatGateway $gateway,
        private readonly UserAiSettingsResolver $settingsResolver,
        private readonly StructuredLogger $logger
    ) {}

    public function findItem(string $characterId, string $intent, ?string $userId = null): ?string
    {
        $items = CharacterInventory::query()
            ->where('character_id', $characterId)
            ->where('is_quick_slot', false)
            ->pluck('item_name')
            ->toArray();

        if (empty($items)) {
            return null;
        }

        $itemsList = implode("\n- ", $items);

        // Usamos un modelo barato como Gatekeeper para esta tarea map-reduce
        $model = $this->settingsResolver->resolveAgentModel($userId, 'gatekeeper');

        $prompt = "Eres un asistente que filtra inventarios.
El jugador quiere: '{$intent}'.
Inventario disponible en la mochila:
- {$itemsList}

Si alguno de estos objetos sirve para el propósito, responde ÚNICAMENTE con el nombre exacto del objeto tal cual aparece en la lista.
Si ninguno sirve razonablemente para la acción, responde ÚNICAMENTE con la palabra 'NULL'.";

        try {
            $response = $this->gateway->chat($model, [
                ['role' => 'user', 'content' => $prompt]
            ], 0.1, 100);

            $text = trim($response['text'] ?? '');

            if ($text === 'NULL' || $text === '') {
                $this->logger->info('InventorySemanticFilter: Ningún item coincide.', ['intent' => $intent]);
                return null;
            }

            $this->logger->info('InventorySemanticFilter: Item encontrado.', ['intent' => $intent, 'item' => $text]);
            return $text;

        } catch (\Exception $e) {
            $this->logger->error('InventorySemanticFilter: Excepción al consultar LLM.', ['error' => $e->getMessage()]);
            return null;
        }
    }
}

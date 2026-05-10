<?php

namespace App\Domains\Intelligence\Contracts;

interface EmbeddingGatewayInterface
{
    /**
     * @return array<int, float>
     */
    public function embed(string $model, string $text): array;
}

<?php

namespace App\Application\Contracts;

interface EmbeddingGateway
{
    /**
     * Get embeddings for the given text.
     *
     * @param string $model
     * @param string $text
     * @return array<int, float>
     */
    public function embed(string $model, string $text): array;
}

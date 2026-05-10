<?php

namespace App\Domains\Intelligence\Contracts;

interface AgentInterface
{
    /**
     * Ejecuta el agente con el contexto provisto.
     *
     * @param  array<string, mixed>  $context
     * @param  callable(string):void|null  $onChunk  Callback para streaming
     * @return array<string, mixed>
     */
    public function run(array $context, ?callable $onChunk = null): array;
}

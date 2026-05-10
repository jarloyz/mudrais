<?php

namespace App\Domain\Ports;

interface AgentGatewayInterface
{
    /**
     * Analiza el Markdown legacy y genera una representación JSON estandarizada para SQL.
     */
    public function generateSqlMigrationPlan(string $markdownContent): array;

    /**
     * Redacta el siguiente turno de la historia basado en el contexto actual.
     */
    public function draftNextTurn(array $context, string $userMessage): string;

    /**
     * Revisa el borrador generado en busca de inconsistencias narrativas.
     */
    public function reviewDraft(string $draft, array $context): array;

    /**
     * Extrae un perfil estructurado de personaje a partir de textos libres.
     */
    public function extractCharacterProfile(array $documents): array;
}

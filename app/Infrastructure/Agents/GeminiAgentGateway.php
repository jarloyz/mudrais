<?php

namespace App\Infrastructure\Agents;

use App\Domain\Ports\AgentGatewayInterface;

class GeminiAgentGateway implements AgentGatewayInterface
{
    private StoryWriterAgent $writerAgent;
    private StoryQAAgent $qaAgent;
    private CharacterProfileAgent $characterAgent;
    private SqlMigrationAgent $migrationAgent;

    public function __construct(
        StoryWriterAgent $writerAgent,
        StoryQAAgent $qaAgent,
        CharacterProfileAgent $characterAgent,
        SqlMigrationAgent $migrationAgent
    ) {
        $this->writerAgent = $writerAgent;
        $this->qaAgent = $qaAgent;
        $this->characterAgent = $characterAgent;
        $this->migrationAgent = $migrationAgent;
    }

    public function generateSqlMigrationPlan(string $markdownContent): array
    {
        return $this->migrationAgent->generatePlan($markdownContent);
    }

    public function draftNextTurn(array $context, string $userMessage): string
    {
        return $this->writerAgent->generateDraft($context, $userMessage);
    }

    public function reviewDraft(string $draft, array $context): array
    {
        return $this->qaAgent->analyzeDraft($draft, $context);
    }

    public function extractCharacterProfile(array $documents): array
    {
        return $this->characterAgent->extractProfile($documents);
    }
}

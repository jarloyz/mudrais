<?php

namespace App\Support;

use App\Infrastructure\Ai\Prompts\ConfiguredAgents\ConfiguredAgentPromptContract;
use App\Infrastructure\Ai\Prompts\ConfiguredAgents\DirectorPrompt;
use App\Infrastructure\Ai\Prompts\ConfiguredAgents\EditorPrompt;
use App\Infrastructure\Ai\Prompts\ConfiguredAgents\ExpanderPrompt;
use App\Infrastructure\Ai\Prompts\ConfiguredAgents\ExtractorPrompt;
use App\Infrastructure\Ai\Prompts\ConfiguredAgents\ImproverPrompt;
use App\Infrastructure\Ai\Prompts\ConfiguredAgents\ImporterPrompt;
use App\Infrastructure\Ai\Prompts\ConfiguredAgents\QaPrompt;
use App\Infrastructure\Ai\Prompts\ConfiguredAgents\RewriterPrompt;
use App\Infrastructure\Ai\Prompts\ConfiguredAgents\RouterPrompt;
use App\Infrastructure\Ai\Prompts\ConfiguredAgents\StatekeeperPrompt;
use App\Infrastructure\Ai\Prompts\ConfiguredAgents\SummaryPrompt;

class ConfiguredAgentPromptRegistry
{
    /**
     * @return array<string, class-string<ConfiguredAgentPromptContract>>
     */
    public function definitions(): array
    {
        return [
            'router' => RouterPrompt::class,
            'extractor' => ExtractorPrompt::class,
            'evidence_summarizer' => SummaryPrompt::class,
            'summarizer_full' => SummaryPrompt::class,
            'summarizer_incremental' => SummaryPrompt::class,
            'history_summary_incremental' => SummaryPrompt::class,
            'history_summary_full' => SummaryPrompt::class,
            'improver' => ImproverPrompt::class,
            'writer_qa_pass' => RewriterPrompt::class,
            'rewriter' => RewriterPrompt::class,
            'expander' => ExpanderPrompt::class,
            'statekeeper' => StatekeeperPrompt::class,
            'importer' => ImporterPrompt::class,
            'director' => DirectorPrompt::class,
            'editor' => EditorPrompt::class,
            'qa' => QaPrompt::class,
        ];
    }

    public function has(string $agentKey): bool
    {
        return array_key_exists($agentKey, $this->definitions());
    }

    public function for(string $agentKey): ConfiguredAgentPromptContract
    {
        $class = $this->definitions()[$agentKey] ?? SummaryPrompt::class;

        return new $class($agentKey);
    }
}

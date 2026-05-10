<?php

namespace App\Support;

use App\Infrastructure\Ai\Agents\ConfiguredChroniclerAgent;
use App\Infrastructure\Ai\Agents\ConfiguredSummarizerAgent;
use App\Infrastructure\Ai\Agents\GenericConfiguredAgent;
use App\Infrastructure\Ai\Agents\SimpleSceneWriterAgent;
use App\Infrastructure\Ai\Prompts\SimpleSceneWriterPrompt;
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

class ConfigurableAgentRegistry
{
    /**
     * @return array<string, array{implementation:class-string,kind:string,prompt_builder:class-string|null}>
     */
    public function definitions(): array
    {
        return [
            'router' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => RouterPrompt::class],
            'extractor' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => ExtractorPrompt::class],
            'summarizer' => ['implementation' => ConfiguredSummarizerAgent::class, 'kind' => 'specialized', 'prompt_builder' => null],
            'evidence_summarizer' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => SummaryPrompt::class],
            'summarizer_full' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => SummaryPrompt::class],
            'summarizer_incremental' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => SummaryPrompt::class],
            'history_summary_incremental' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => SummaryPrompt::class],
            'history_summary_full' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => SummaryPrompt::class],
            'improver' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => ImproverPrompt::class],
            'writer' => ['implementation' => SimpleSceneWriterAgent::class, 'kind' => 'specialized', 'prompt_builder' => SimpleSceneWriterPrompt::class],
            'writer_qa_pass' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => RewriterPrompt::class],
            'rewriter' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => RewriterPrompt::class],
            'expander' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => ExpanderPrompt::class],
            'statekeeper' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => StatekeeperPrompt::class],
            'importer' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => ImporterPrompt::class],
            'director' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => DirectorPrompt::class],
            'editor' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => EditorPrompt::class],
            'chronicler' => ['implementation' => ConfiguredChroniclerAgent::class, 'kind' => 'specialized', 'prompt_builder' => null],
            'qa' => ['implementation' => GenericConfiguredAgent::class, 'kind' => 'generic', 'prompt_builder' => QaPrompt::class],
        ];
    }

    public function has(string $agentKey): bool
    {
        return array_key_exists($agentKey, $this->definitions());
    }

    /**
     * @return array{implementation:class-string,kind:string,prompt_builder:class-string|null}|null
     */
    public function definitionFor(string $agentKey): ?array
    {
        return $this->definitions()[$agentKey] ?? null;
    }
}

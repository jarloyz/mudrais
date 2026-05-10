<?php

namespace App\Infrastructure\Ai\Agents;

use App\Application\Contracts\AiChatGateway;
use App\Application\Contracts\QaLoopRunner;
use App\Application\Contracts\StructuredLogger;
use App\Domain\Scene\Activity;
use App\Support\LogPreview;
use App\Support\ConfiguredAgentPromptRegistry;
use App\Support\UserAiSettingsResolver;
use Throwable;

final class LaravelQaLoopRunner implements QaLoopRunner
{
    private const SEVERITY_ORDER = [
        'none' => 0,
        'minor' => 1,
        'medium' => 2,
        'major' => 3,
    ];

    public function __construct(
        private readonly AiChatGateway $aiChatGateway,
        private readonly UserAiSettingsResolver $userAiSettingsResolver,
        private readonly ConfiguredAgentPromptRegistry $promptRegistry,
        private readonly StructuredLogger $logger,
    ) {
    }

    public function run(
        Activity $scene,
        array $context,
        string $userMessage,
        string $mode,
        string $outputMd,
        array $qaLoop,
        ?string $userId = null,
    ): array {
        $config = $this->normalizeConfig($qaLoop);
        $logger = $this->logger->withContext([
            'layer' => 'infrastructure',
            'component' => 'qa_loop_runner',
            'sceneId' => $scene->id,
            'userId' => $userId,
            'mode' => $mode,
            'qaEnabled' => $config['enabled'],
            'qaMaxPasses' => $config['max_passes'],
            'qaMinSeverity' => $config['min_severity'],
        ]);

        if ($config['enabled'] !== true) {
            $logger->info('QA loop omitido porque esta deshabilitado');

            return [
                'enabled' => false,
                'triggered' => false,
                'passes' => 0,
                'highestSeverity' => 'none',
                'status' => 'disabled',
                'issues' => [],
                'outputMd' => $outputMd,
            ];
        }

        $qaAgent = new GenericConfiguredAgent(
            $this->aiChatGateway,
            $this->userAiSettingsResolver,
            $this->promptRegistry,
            $this->logger,
            'qa',
        );
        $writerQaPassAgent = new GenericConfiguredAgent(
            $this->aiChatGateway,
            $this->userAiSettingsResolver,
            $this->promptRegistry,
            $this->logger,
            'writer_qa_pass',
        );

        $currentOutput = $outputMd;
        $allIssues = [];
        $highestSeverity = 'none';
        $highestSeenSeverity = 'none';
        $triggered = false;
        $status = 'approved';
        $passCount = 0;

        for ($pass = 1; $pass <= $config['max_passes']; $pass++) {
            $passCount = $pass;
            $qaPayload = [
                'scene' => [
                    'id' => $scene->id,
                    'title' => $scene->title,
                    'objective' => $scene->objective,
                ],
                'mode' => $mode,
                'user_message' => $userMessage,
                'candidate_output' => $currentOutput,
                'context' => $this->compactContext($context),
                'loop' => [
                    'pass' => $pass,
                    'max_passes' => $config['max_passes'],
                    'min_severity' => $config['min_severity'],
                ],
            ];
            $logger->info('Inicio de pasada QA', [
                'pass' => $pass,
                'candidateChars' => mb_strlen($currentOutput),
            ]);
            $logger->debug('QA loop payload preparado', [
                'pass' => $pass,
                'candidatePreview' => LogPreview::text($currentOutput, 3000),
                'payload_preview' => LogPreview::json($qaPayload, 12000),
            ]);

            try {
                $qaResponse = $qaAgent->generate($qaPayload, $userId);
            } catch (Throwable $exception) {
                $logger->warning('QA loop fallo al evaluar candidato', [
                    'pass' => $pass,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                $status = 'qa_failed';
                break;
            }

            $review = $this->parseQaReview($qaResponse['text'] ?? '');
            if ($review === null) {
                $logger->warning('QA loop recibio salida invalida', [
                    'pass' => $pass,
                    'qaPreview' => LogPreview::text((string) ($qaResponse['text'] ?? ''), 400),
                ]);

                $status = 'qa_invalid';
                break;
            }

            $issues = $review['issues'];
            $highestSeverity = $this->highestSeverity($issues);
            if ($this->meetsThreshold($highestSeverity, $highestSeenSeverity)) {
                $highestSeenSeverity = $highestSeverity;
            }
            $allIssues = $issues;

            $logger->info('QA loop evaluo candidato', [
                'pass' => $pass,
                'status' => $review['status'],
                'highestSeverity' => $highestSeverity,
                'issueCount' => count($issues),
            ]);
            $logger->debug('QA loop review parseado', [
                'pass' => $pass,
                'review' => $review,
                'raw_review_preview' => LogPreview::text((string) ($qaResponse['text'] ?? ''), 4000),
            ]);

            if ($issues === [] || ! $this->meetsThreshold($highestSeverity, $config['min_severity'])) {
                $status = $review['status'] === 'approved' ? 'approved' : 'below_threshold';
                break;
            }

            $triggered = true;

            if ($pass >= $config['max_passes']) {
                $status = 'max_passes_reached';
                $logger->warning('QA loop alcanzo maximo de pasadas', [
                    'pass' => $pass,
                    'highestSeverity' => $highestSeverity,
                    'issueCount' => count($issues),
                ]);
                break;
            }

            $rewritePayload = [
                'scene' => [
                    'id' => $scene->id,
                    'title' => $scene->title,
                    'objective' => $scene->objective,
                ],
                'mode' => $mode,
                'user_message' => $userMessage,
                'candidate_output' => $currentOutput,
                'issues' => $issues,
                'context' => $this->compactContext($context),
            ];
            $logger->debug('QA loop rewrite payload preparado', [
                'pass' => $pass,
                'rewrite_payload_preview' => LogPreview::json($rewritePayload, 12000),
            ]);

            try {
                $rewriteResponse = $writerQaPassAgent->generate($rewritePayload, $userId);
            } catch (Throwable $exception) {
                $logger->warning('QA loop fallo al reescribir candidato', [
                    'pass' => $pass,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);

                $status = 'rewrite_failed';
                break;
            }

            $rewrittenText = trim((string) ($rewriteResponse['text'] ?? ''));
            if ($rewrittenText === '') {
                $logger->warning('QA loop recibio reescritura vacia', [
                    'pass' => $pass,
                ]);

                $status = 'rewrite_empty';
                break;
            }

            $currentOutput = $this->stripCodeFences($rewrittenText);
            $status = 'revised';

            $logger->info('QA loop aplico reescritura', [
                'pass' => $pass,
                'rewrittenChars' => mb_strlen($currentOutput),
            ]);
            $logger->debug('QA loop reescritura recibida', [
                'pass' => $pass,
                'rewrittenPreview' => LogPreview::text($currentOutput, 4000),
            ]);
        }

        $logger->info('QA loop finalizado', [
            'status' => $status,
            'passes' => $passCount,
            'triggered' => $triggered,
            'highestSeverity' => $highestSeenSeverity,
            'issueCount' => count($allIssues),
            'finalOutputChars' => mb_strlen($currentOutput),
        ]);

        return [
            'enabled' => true,
            'triggered' => $triggered,
            'passes' => $passCount,
            'highestSeverity' => $highestSeenSeverity,
            'status' => $status,
            'issues' => $allIssues,
            'outputMd' => $currentOutput,
        ];
    }

    /**
     * @param array<string, mixed> $qaLoop
     * @return array{enabled:bool,max_passes:int,min_severity:string}
     */
    private function normalizeConfig(array $qaLoop): array
    {
        $minSeverity = trim((string) ($qaLoop['min_severity'] ?? 'medium'));

        return [
            'enabled' => (bool) ($qaLoop['enabled'] ?? false),
            'max_passes' => max(1, min(3, (int) ($qaLoop['max_passes'] ?? 1))),
            'min_severity' => in_array($minSeverity, ['minor', 'medium', 'major'], true) ? $minSeverity : 'medium',
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function compactContext(array $context): array
    {
        return [
            'continuity_id' => $context['continuity_id'] ?? null,
            'location' => $context['location'] ?? null,
            'characters' => array_slice((array) ($context['characters'] ?? []), 0, 8),
            'rolling_summary' => $context['rolling_summary'] ?? null,
            'scene_opening' => $context['scene_opening'] ?? null,
            'recent_messages' => array_slice((array) ($context['recent_messages'] ?? []), -4),
        ];
    }

    /**
     * @return array{status:string,issues:array<int, array{severity:string,code:string,message:string,instruction:string}>}|null
     */
    private function parseQaReview(string $text): ?array
    {
        $decoded = json_decode($this->stripCodeFences($text), true);
        if (! is_array($decoded)) {
            return null;
        }

        $issues = [];
        foreach ((array) ($decoded['issues'] ?? []) as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $severity = trim((string) ($issue['severity'] ?? 'minor'));
            if (! in_array($severity, ['minor', 'medium', 'major'], true)) {
                $severity = 'minor';
            }

            $message = trim((string) ($issue['message'] ?? ''));
            $instruction = trim((string) ($issue['instruction'] ?? ''));

            if ($message === '' || $instruction === '') {
                continue;
            }

            $issues[] = [
                'severity' => $severity,
                'code' => trim((string) ($issue['code'] ?? 'qa_issue')),
                'message' => $message,
                'instruction' => $instruction,
            ];
        }

        $status = trim((string) ($decoded['status'] ?? 'approved'));
        if (! in_array($status, ['approved', 'needs_revision'], true)) {
            $status = $issues === [] ? 'approved' : 'needs_revision';
        }

        return [
            'status' => $status,
            'issues' => $issues,
        ];
    }

    /**
     * @param array<int, array{severity:string,code:string,message:string,instruction:string}> $issues
     */
    private function highestSeverity(array $issues): string
    {
        $winner = 'none';

        foreach ($issues as $issue) {
            $severity = $issue['severity'] ?? 'none';
            if ((self::SEVERITY_ORDER[$severity] ?? 0) > (self::SEVERITY_ORDER[$winner] ?? 0)) {
                $winner = $severity;
            }
        }

        return $winner;
    }

    private function meetsThreshold(string $severity, string $threshold): bool
    {
        return (self::SEVERITY_ORDER[$severity] ?? 0) >= (self::SEVERITY_ORDER[$threshold] ?? 0);
    }

    private function stripCodeFences(string $text): string
    {
        $clean = trim($text);
        $clean = preg_replace('/^```(?:json|markdown|md|text)?\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/```$/', '', $clean) ?? $clean;

        return trim($clean);
    }

}

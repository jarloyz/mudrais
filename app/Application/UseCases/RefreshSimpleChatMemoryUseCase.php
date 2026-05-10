<?php

namespace App\Application\UseCases;

use App\Application\Agents\SummarizerAgent;
use App\Application\Contracts\SceneCacheRepository;
use App\Application\Contracts\SceneRepository;
use App\Application\Contracts\StructuredLogger;
use App\Support\LogPreview;
use App\Support\SimpleChatMemoryManager;
use RuntimeException;
use Throwable;

class RefreshSimpleChatMemoryUseCase
{
    public function __construct(
        private readonly SceneRepository $sceneRepository,
        private readonly SceneCacheRepository $sceneCacheRepository,
        private readonly SimpleChatMemoryManager $simpleChatMemoryManager,
        private readonly SummarizerAgent $summarizerAgent,
        private readonly StructuredLogger $logger,
    ) {
    }

    public function execute(string $sceneId, string $userMessage, string $outputMd, ?string $userId = null): void
    {
        $scene = $this->sceneRepository->findById($sceneId);
        if (! $scene) {
            throw new RuntimeException("sceneId no encontrado para refresh de memoria: {$sceneId}");
        }

        $logger = $this->logger->withContext([
            'layer' => 'application',
            'use_case' => 'refresh_simple_chat_memory',
            'sceneId' => $sceneId,
            'userId' => $userId,
        ]);

        $logger->info('Inicio de refresh de memoria simple');
        $logger->debug('Refresh de memoria simple payload preparado', [
            'userMessagePreview' => LogPreview::text($userMessage, 3000),
            'outputPreview' => LogPreview::text($outputMd, 3000),
        ]);

        $updatedMemory = $this->simpleChatMemoryManager->buildUpdatedMemory($sceneId, $scene, $userMessage, $outputMd);
        $logger->debug('Memoria simple construida', [
            'memoryPreview' => LogPreview::json($updatedMemory, 12000),
        ]);

        if ($this->simpleChatMemoryManager->shouldSummarizePending($updatedMemory)) {
            try {
                $pendingMessages = $this->simpleChatMemoryManager->pendingMessages($updatedMemory);
                $logger->debug('Se enviaran mensajes pendientes al summarizer', [
                    'pendingMessagesPreview' => LogPreview::json($pendingMessages, 12000),
                ]);
                $updatedMemory = $this->simpleChatMemoryManager->applySummary(
                    $updatedMemory,
                    $this->summarizerAgent->summarizeIncremental(
                        sceneId: $sceneId,
                        existingSummary: (string) ($updatedMemory['rolling_summary'] ?? ''),
                        messages: $pendingMessages,
                        userId: $userId,
                    ),
                );

                $logger->info('Resumen incremental generado con agente summarizer', [
                    'summaryChars' => mb_strlen((string) ($updatedMemory['rolling_summary'] ?? '')),
                ]);
                $logger->debug('Memoria simple tras aplicar summary', [
                    'memoryPreview' => LogPreview::json($updatedMemory, 12000),
                ]);
            } catch (Throwable $exception) {
                $logger->warning('Summarizer incremental fallo; se conserva cola sin resumir', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->simpleChatMemoryManager->store($sceneId, $updatedMemory);
        $updatedMemory = $this->sceneCacheRepository->get('simple_chat_memory', $sceneId) ?? [];

        $this->sceneCacheRepository->put('history_summary', $sceneId, [
            'scene_key' => $sceneId,
            'summary' => (string) ($updatedMemory['rolling_summary'] ?? ''),
            'recent_messages' => $updatedMemory['recent_messages'] ?? [],
            'unsummarized_messages' => $updatedMemory['unsummarized_messages'] ?? [],
        ]);

        $logger->info('Refresh de memoria simple completado', [
            'recentMessageCount' => count($updatedMemory['recent_messages'] ?? []),
            'summaryChars' => mb_strlen((string) ($updatedMemory['rolling_summary'] ?? '')),
        ]);
        $logger->debug('Refresh de memoria simple persistido', [
            'historySummaryPreview' => LogPreview::json([
                'summary' => (string) ($updatedMemory['rolling_summary'] ?? ''),
                'recent_messages' => $updatedMemory['recent_messages'] ?? [],
                'unsummarized_messages' => $updatedMemory['unsummarized_messages'] ?? [],
            ], 12000),
        ]);
    }
}

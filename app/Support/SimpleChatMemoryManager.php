<?php

namespace App\Support;

use App\Application\Contracts\SceneCacheRepository;
use App\Domain\Scene\Activity;
class SimpleChatMemoryManager
{
    public function __construct(
        private readonly SceneCacheRepository $sceneCacheRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function enrichContext(string $sceneId, Activity $scene, array $context): array
    {
        $memory = $this->sceneCacheRepository->get('simple_chat_memory', $sceneId) ?? [];

        $context['sceneOpening'] = $this->normalizeSceneOpening(
            $memory['scene_opening'] ?? null,
            (string) ($scene->draft ?? '')
        );
        $context['historySummary'] = $this->normalizeSummary($memory['rolling_summary'] ?? null);
        $context['recentMessages'] = $this->normalizeMessages($memory['recent_messages'] ?? null);

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUpdatedMemory(string $sceneId, Activity $scene, string $userMessage, string $outputMd): array
    {
        $memory = $this->sceneCacheRepository->get('simple_chat_memory', $sceneId) ?? [];
        $recentMessages = $this->normalizeMessages($memory['recent_messages'] ?? null);
        $unsummarizedMessages = $this->normalizeMessages($memory['unsummarized_messages'] ?? null);
        $rollingSummary = $this->normalizeSummary($memory['rolling_summary'] ?? null);

        $newMessages = [
            ['role' => 'user', 'content' => trim($userMessage)],
            ['role' => 'assistant', 'content' => trim($outputMd)],
        ];

        $recentMessages = array_values(array_filter([...$recentMessages, ...$newMessages], $this->validMessage(...)));
        $unsummarizedMessages = array_values(array_filter([...$unsummarizedMessages, ...$newMessages], $this->validMessage(...)));

        $recentWindowSize = max(1, (int) config('historia.simple_memory.recent_messages', 4));
        if (count($recentMessages) > $recentWindowSize) {
            $recentMessages = array_slice($recentMessages, -$recentWindowSize);
        }

        return [
            'scene_key' => $sceneId,
            'scene_opening' => $this->normalizeSceneOpening(
                $memory['scene_opening'] ?? null,
                (string) ($scene->draft ?? '')
            ),
            'rolling_summary' => $rollingSummary,
            'recent_messages' => $recentMessages,
            'unsummarized_messages' => $unsummarizedMessages,
        ];
    }

    public function update(string $sceneId, Activity $scene, string $userMessage, string $outputMd): void
    {
        $this->store($sceneId, $this->buildUpdatedMemory($sceneId, $scene, $userMessage, $outputMd));
    }

    /**
     * @param array<string, mixed> $memory
     */
    public function store(string $sceneId, array $memory): void
    {
        $this->sceneCacheRepository->put('simple_chat_memory', $sceneId, $memory);
    }

    /**
     * @param array<string, mixed> $memory
     */
    public function shouldSummarizePending(array $memory): bool
    {
        return $this->shouldSummarize($this->normalizeMessages($memory['unsummarized_messages'] ?? null));
    }

    /**
     * @param array<string, mixed> $memory
     * @return array<int, array{role:string,content:string}>
     */
    public function pendingMessages(array $memory): array
    {
        return $this->normalizeMessages($memory['unsummarized_messages'] ?? null);
    }

    /**
     * @param array<string, mixed> $memory
     * @return array<string, mixed>
     */
    public function applySummary(array $memory, string $batchSummary): array
    {
        $memory['rolling_summary'] = $this->mergeSummaries(
            $this->normalizeSummary($memory['rolling_summary'] ?? null),
            $batchSummary,
        );
        $memory['unsummarized_messages'] = [];

        return $memory;
    }

    /**
     * @param mixed $messages
     * @return array<int, array{role:string,content:string}>
     */
    private function normalizeMessages(mixed $messages): array
    {
        if (! is_array($messages)) {
            return [];
        }

        $normalized = [];

        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = trim((string) ($message['role'] ?? ''));
            $content = trim((string) ($message['content'] ?? ''));

            if ($role === '' || $content === '') {
                continue;
            }

            $normalized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $normalized;
    }

    private function normalizeSummary(mixed $summary): string
    {
        return is_string($summary) ? trim($summary) : '';
    }

    private function normalizeSceneOpening(mixed $storedOpening, string $fallbackDraft): string
    {
        $opening = is_string($storedOpening) ? trim($storedOpening) : '';
        if ($opening !== '') {
            return $opening;
        }

        return $this->clip($fallbackDraft, (int) config('historia.simple_memory.scene_opening_max_chars', 1200));
    }

    /**
     * @param array<int, array{role:string,content:string}> $messages
     */
    private function shouldSummarize(array $messages): bool
    {
        if ($messages === []) {
            return false;
        }

        $messageThreshold = max(1, (int) config('historia.simple_memory.batch_message_count', 6));
        if (count($messages) >= $messageThreshold) {
            return true;
        }

        $chars = array_sum(array_map(
            static fn (array $message): int => mb_strlen($message['content']),
            $messages,
        ));

        return $chars >= max(1, (int) config('historia.simple_memory.batch_max_chars', 4000));
    }

    private function mergeSummaries(string $existingSummary, string $batchSummary): string
    {
        $parts = array_values(array_filter([
            trim($existingSummary),
            trim($batchSummary),
        ]));

        return $this->clip(
            implode("\n", $parts),
            (int) config('historia.simple_memory.summary_max_chars', 3000)
        );
    }

    /**
     * @param array{role:string,content:string} $message
     */
    private function validMessage(array $message): bool
    {
        return trim($message['role']) !== '' && trim($message['content']) !== '';
    }

    private function clip(string $value, int $maxChars): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(0, $maxChars - 3))).'...';
    }
}

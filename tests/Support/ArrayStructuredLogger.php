<?php

namespace Tests\Support;

use App\Application\Contracts\StructuredLogger;

class ArrayStructuredLogger implements StructuredLogger
{
    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, mixed> $context
     */
    public function __construct(
        public array &$entries = [],
        private array $context = [],
    ) {
    }

    public function info(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'info', 'message' => $message, 'context' => [...$this->context, ...$context]];
    }

    public function warning(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'warning', 'message' => $message, 'context' => [...$this->context, ...$context]];
    }

    public function error(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'error', 'message' => $message, 'context' => [...$this->context, ...$context]];
    }

    public function debug(string $message, array $context = []): void
    {
        $this->entries[] = ['level' => 'debug', 'message' => $message, 'context' => [...$this->context, ...$context]];
    }

    public function withContext(array $context = []): StructuredLogger
    {
        return new self($this->entries, [...$this->context, ...$context]);
    }
}

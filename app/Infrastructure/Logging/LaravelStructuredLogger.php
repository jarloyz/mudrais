<?php

namespace App\Infrastructure\Logging;

use App\Application\Contracts\StructuredLogger;
use Illuminate\Support\Facades\Log;

class LaravelStructuredLogger implements StructuredLogger
{
    public function __construct(
        private readonly array $baseContext = [],
        private readonly ?string $channel = null,
    ) {
    }

    public function info(string $message, array $context = []): void
    {
        $this->logger()->info($message, $this->mergeContext($context));
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logger()->warning($message, $this->mergeContext($context));
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger()->error($message, $this->mergeContext($context));
    }

    public function debug(string $message, array $context = []): void
    {
        $this->logger()->debug($message, $this->mergeContext($context));
    }

    public function withContext(array $context = []): StructuredLogger
    {
        return new self($this->mergeContext($context), $this->channel);
    }

    private function logger(): \Psr\Log\LoggerInterface
    {
        return $this->channel ? Log::channel($this->channel) : Log::getFacadeRoot();
    }

    private function mergeContext(array $context): array
    {
        return array_filter(
            [...$this->baseContext, ...$context],
            static fn (mixed $value): bool => $value !== null
        );
    }
}

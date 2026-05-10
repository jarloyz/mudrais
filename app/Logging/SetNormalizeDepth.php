<?php

namespace App\Logging;

use Monolog\Formatter\NormalizerFormatter;

class SetNormalizeDepth
{
    public function __construct(private readonly int $depth = 20) {}

    public function __invoke(mixed $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $formatter = $handler->getFormatter();
            if ($formatter instanceof NormalizerFormatter) {
                $formatter->setMaxNormalizeDepth($this->depth);
            }
        }
    }
}

<?php

namespace App\Application\Contracts;

interface EventEngineRepository
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function evaluateAndApplyTriggers(array $input): array;
}

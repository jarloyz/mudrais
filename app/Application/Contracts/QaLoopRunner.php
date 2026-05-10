<?php

namespace App\Application\Contracts;

use App\Domain\Scene\Activity;

interface QaLoopRunner
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $qaLoop
     * @return array{
     *   enabled:bool,
     *   triggered:bool,
     *   passes:int,
     *   highestSeverity:string,
     *   status:string,
     *   issues:array<int, array{severity:string,code:string,message:string,instruction:string}>,
     *   outputMd:string
     * }
     */
    public function run(
        Activity $scene,
        array $context,
        string $userMessage,
        string $mode,
        string $outputMd,
        array $qaLoop,
        ?string $userId = null,
    ): array;
}

<?php

namespace App\Application\Contracts;

interface ContinuityRepository
{
    /**
     * @return array<string, mixed>
     */
    public function requireById(string $continuityId): array;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createBranch(array $input): array;

    /**
     * @param array<string, mixed> $input
     */
    public function ensureSceneStateFromBase(array $input): void;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function requireSceneState(array $input): array;

    /**
     * @param array<string, mixed> $input
     */
    public function appendSceneDraft(array $input): void;

    /**
     * @param array<string, mixed> $input
     */
    public function replaceSceneDraft(array $input): void;

    /**
     * @param array<string, mixed> $input
     */
    public function nextTurnIndex(array $input): int;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function appendTurn(array $input): array;

    /**
     * @param array<string, mixed> $input
     */
    public function appendStateChanges(array $input): int;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function getHeadCommit(array $input): ?array;

    /**
     * @return array<string, mixed>
     */
    public function requireCommitById(string $commitId): array;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function requireCommitByTurn(array $input): array;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function checkoutCommit(array $input): array;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createCommitFromCurrentState(array $input): array;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createBranchFromCommit(array $input): array;

    /**
     * @param array<string, mixed> $input
     */
    public function setSceneActiveContinuity(array $input): void;

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveSceneContinuity(string $sceneId): ?array;
}

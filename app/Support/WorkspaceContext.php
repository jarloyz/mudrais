<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;

class WorkspaceContext
{
    private const SESSION_KEY = 'historia.workspace_context';

    /**
     * @return array{vault_id:string,scene_id:string,continuity_id:string,user_id:string,mode:string,apply:bool}
     */
    public static function defaults(): array
    {
        $stored = session(self::SESSION_KEY, []);
        $authenticatedUserId = Auth::id();

        return [
            'vault_id' => (string) ($stored['vault_id'] ?? ''),
            'scene_id' => (string) ($stored['scene_id'] ?? ''),
            'continuity_id' => (string) ($stored['continuity_id'] ?? ''),
            'user_id' => (string) ($authenticatedUserId ? (string) $authenticatedUserId : ($stored['user_id'] ?? '')),
            'mode' => (string) ($stored['mode'] ?? 'write_scene'),
            'apply' => (bool) ($stored['apply'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function store(array $context): void
    {
        session([
            self::SESSION_KEY => array_merge(
                self::defaults(),
                Arr::only($context, ['vault_id', 'scene_id', 'continuity_id', 'user_id', 'mode', 'apply']),
            ),
        ]);
    }
}

<?php

namespace App\Services\Auth;

use App\Models\User;

class UserProfileService
{
    public static function ensureProfile(User $user): void
    {
        if (!$user->profile) {
            $user->profile()->create([
                'display_name' => $user->name,
                'timezone' => 'UTC',
                'locale' => 'es',
            ]);
        }
    }
}

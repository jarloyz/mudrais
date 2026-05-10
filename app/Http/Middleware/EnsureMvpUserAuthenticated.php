<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureMvpUserAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Schema::hasTable('users')) {
            return $next($request);
        }

        if (! Auth::check()) {
            Auth::login($this->ensureMvpUser());
        }

        return $next($request);
    }

    private function ensureMvpUser(): User
    {
        $user = User::query()->find(1);

        if ($user) {
            if (! $user->hasExternalIdentity()) {
                $user->identity_provider = 'local-dev';
                $user->identity_uuid = '00000000-0000-4000-8000-000000000001';
                $user->save();
            }

            return $user;
        }

        $user = new User();
        $user->id = 1;
        $user->name = 'Usuario MVP';
        $user->email = 'mvp@historia.local';
        $user->email_verified_at = now();
        $user->identity_provider = 'local-dev';
        $user->identity_uuid = '00000000-0000-4000-8000-000000000001';
        $user->password = Hash::make('password');
        $user->save();

        return $user;
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!Auth::check()) {
            Log::warning('[EnsureUserHasRole] User not authenticated.', ['ip' => $request->ip()]);
            abort(401, 'Unauthorized');
        }

        $user = $request->user();

        if (!$user->hasAnyRole($roles)) {
            Log::warning('[EnsureUserHasRole] User lacks required role.', ['user_id' => $user->id, 'required_roles' => $roles]);
            abort(403, 'Forbidden');
        }

        Log::info('[EnsureUserHasRole] User role authorized.', ['user_id' => $user->id, 'roles' => $roles]);

        return $next($request);
    }
}

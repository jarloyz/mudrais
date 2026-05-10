<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ContextualLogging
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = [];

        // Check headers, query params or JSON body for context variables
        if ($request->has('continuity_id')) {
            $context['continuity_id'] = $request->input('continuity_id');
        } elseif ($request->header('X-Historia-Continuity-Id')) {
            $context['continuity_id'] = $request->header('X-Historia-Continuity-Id');
        }

        if ($request->has('scene_id')) {
            $context['scene_id'] = $request->input('scene_id');
        } elseif ($request->header('X-Historia-Activity-Id')) {
            $context['scene_id'] = $request->header('X-Historia-Activity-Id');
        }

        if ($request->user()) {
            $context['user_id'] = $request->user()->id;
        }

        if (!empty($context)) {
            Log::withContext($context);
        }

        return $next($request);
    }
}

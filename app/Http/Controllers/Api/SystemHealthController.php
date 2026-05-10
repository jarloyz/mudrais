<?php

namespace App\Http\Controllers\Api;

use App\Application\Contracts\StructuredLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SystemHealthController extends Controller
{
    public function __invoke(StructuredLogger $logger): JsonResponse
    {
        $logger
            ->withContext([
                'layer' => 'http',
                'endpoint' => 'api.health',
            ])
            ->info('Healthcheck solicitado');

        return response()->json([
            'status' => 'ok',
            'application' => config('app.name'),
            'runtime' => 'laravel',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}

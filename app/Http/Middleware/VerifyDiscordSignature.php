<?php

namespace App\Http\Middleware;

use App\Services\Discord\Contracts\DiscordSignatureValidator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyDiscordSignature
{
    public function __construct(private readonly DiscordSignatureValidator $validator) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->validator->isValid($request)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        return $next($request);
    }
}

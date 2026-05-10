<?php

namespace App\Services\Discord\Contracts;

use Illuminate\Http\Request;

interface DiscordSignatureValidator
{
    /**
     * Determina si la request de Discord tiene una firma válida.
     */
    public function isValid(Request $request): bool;
}

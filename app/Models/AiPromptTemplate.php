<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AiPromptTemplate extends Model
{
    use \App\Traits\HasUuidV7;

    protected $fillable = ['key', 'description', 'body'];

    private const CACHE_TTL = 300; // 5 minutos

    /**
     * Devuelve el body del template por clave.
     * Si no existe en DB, usa el fallback PHP y lo loguea.
     */
    public static function getBody(string $key, string $fallback = ''): string
    {
        return Cache::remember("ai_prompt_template_{$key}", self::CACHE_TTL, function () use ($key, $fallback) {
            $template = static::where('key', $key)->value('body');

            if ($template !== null) {
                Log::debug("[AiPromptTemplate] Prompt '{$key}' cargado desde DB.");
                return $template;
            }

            Log::warning("[AiPromptTemplate] Prompt '{$key}' no encontrado en DB — usando fallback PHP.");
            return $fallback;
        });
    }

    /**
     * Devuelve el body del template por clave.
     * Lanza RuntimeException si la clave no existe — el DB es la única fuente de verdad.
     */
    public static function getBodyOrFail(string $key): string
    {
        return Cache::remember("ai_prompt_template_{$key}", self::CACHE_TTL, function () use ($key): string {
            $body = static::where('key', $key)->value('body');

            if ($body === null) {
                throw new \RuntimeException(
                    "[AiPromptTemplate] Prompt '{$key}' no encontrado en DB. Ejecuta PromptMigrationSeeder."
                );
            }

            Log::debug("[AiPromptTemplate] Prompt '{$key}' cargado desde DB.");

            return $body;
        });
    }

    /**
     * Invalida el caché de un template para que el siguiente acceso lea desde DB.
     */
    public static function invalidateCache(string $key): void
    {
        Cache::forget("ai_prompt_template_{$key}");
    }
}

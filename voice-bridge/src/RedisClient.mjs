/**
 * Cliente Redis directo para el canal de voz.
 * Usa la conexión 'voice' de Laravel (sin prefix) — mismas claves en ambos lados.
 *
 * Dos conexiones separadas porque BLPOP bloquea el socket mientras espera:
 *   - redis        → operaciones no bloqueantes (LPOP, etc.)
 *   - blockingRedis → exclusivo para BLPOP
 */

import Redis from 'ioredis';

const redisConfig = {
  host:     process.env.REDIS_HOST     ?? 'redis',
  port:     parseInt(process.env.REDIS_PORT ?? '6379', 10),
  password: process.env.REDIS_PASSWORD || undefined,
  db:       parseInt(process.env.REDIS_DB   ?? '0', 10),
  lazyConnect: true,
};

const redis         = new Redis(redisConfig);
const blockingRedis = new Redis(redisConfig);

redis.on('error',         (err) => console.error(`[RedisClient] error: ${err.message}`));
blockingRedis.on('error', (err) => console.error(`[RedisClient] blocking error: ${err.message}`));

const KEY_PREFIX = 'voice_next_question:';

/**
 * LPOP no bloqueante — para el early-exit check entre oraciones del Talkator.
 * Retorna la pregunta si ya está lista, null si aún no.
 *
 * @param {string} sessionId
 * @returns {Promise<string|null>}
 */
export async function lpopNextQuestion(sessionId) {
  const result = await redis.lpop(KEY_PREFIX + sessionId);
  return result ?? null;
}

/**
 * BLPOP bloqueante — espera hasta que el Interviewer publique la siguiente pregunta.
 * Retorna null solo si se alcanza el timeout (Interviewer no respondió).
 *
 * @param {string} sessionId
 * @param {number} timeoutSec  Máximo de segundos a esperar (default 60).
 * @returns {Promise<string|null>}
 */
export async function blpopNextQuestion(sessionId, timeoutSec = 60) {
  const result = await blockingRedis.blpop(KEY_PREFIX + sessionId, timeoutSec);
  // ioredis devuelve [key, value] o null en timeout.
  return result ? result[1] : null;
}

/**
 * BLPOP bloqueante — espera hasta que Laravel encole una señal de inicio de sesión.
 * Retorna el payload parseado o null si se alcanza el timeout.
 *
 * @param {number} timeoutSec
 * @returns {Promise<{discord_id:string,guild_id:string,locale:string,interaction_token:string,app_id:string,created_at:number}|null>}
 */
export async function blpopPendingStart(timeoutSec = 30) {
  const result = await blockingRedis.blpop('voice_bridge_pending_starts', timeoutSec);
  if (!result) return null;

  try {
    return JSON.parse(result[1]);
  } catch {
    console.error('[RedisClient] blpopPendingStart: payload JSON inválido', result[1]);
    return null;
  }
}

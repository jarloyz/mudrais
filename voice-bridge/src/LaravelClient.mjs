/**
 * HTTP client para comunicarse con el backend Laravel.
 * Todas las requests llevan X-Voice-Bridge-Secret para autenticación.
 */

const BASE_URL = process.env.LARAVEL_BASE_URL ?? 'http://laravel.test:80';
const SECRET   = process.env.VOICE_BRIDGE_SECRET ?? '';

const HEADERS = {
  'X-Voice-Bridge-Secret': SECRET,
  'Content-Type': 'application/json',
};

/**
 * Inicia una sesión de entrevista de voz.
 * @returns {{ session_id: string, opening_question: string }}
 */
export async function startSession(discordId, discordGuildId, locale = 'es') {
  const res = await fetch(`${BASE_URL}/api/voice/session/start`, {
    method: 'POST',
    headers: HEADERS,
    body: JSON.stringify({ discord_id: discordId, discord_guild_id: discordGuildId, locale }),
  });

  if (!res.ok) {
    const body = await res.text();
    throw new Error(`startSession failed ${res.status}: ${body}`);
  }

  return res.json();
}

/**
 * Envía una transcripción a Laravel y devuelve un ReadableStream de texto
 * (el agente Talkator responde en streaming chunk a chunk).
 * @returns {ReadableStream<Uint8Array>}
 */
export async function postTranscription(sessionId, transcript, discordId) {
  const res = await fetch(`${BASE_URL}/api/voice/transcription`, {
    method: 'POST',
    headers: HEADERS,
    body: JSON.stringify({ session_id: sessionId, transcript, discord_id: discordId }),
  });

  if (!res.ok) {
    const body = await res.text();
    throw new Error(`postTranscription failed ${res.status}: ${body}`);
  }

  return res.body;
}

/**
 * Polling de sesiones pendientes de inicio.
 * Laravel encola la señal cuando el usuario ejecuta /voice-interview vía HTTP interactions.
 * @returns {{ ready: boolean, discord_id?: string, guild_id?: string, locale?: string, interaction_token?: string, app_id?: string }}
 */
export async function pollPendingStart() {
  const res = await fetch(`${BASE_URL}/api/voice/pending-start`, {
    headers: HEADERS,
  });

  if (!res.ok) {
    throw new Error(`pollPendingStart failed ${res.status}`);
  }

  return res.json();
}

/**
 * Polling de la siguiente pregunta del Interviewer.
 * @returns {{ ready: boolean, question?: string }}
 */
export async function pollNextQuestion(sessionId) {
  const res = await fetch(`${BASE_URL}/api/voice/next-question/${sessionId}`, {
    headers: HEADERS,
  });

  if (!res.ok) {
    throw new Error(`pollNextQuestion failed ${res.status}`);
  }

  return res.json();
}

/**
 * Descarga un audio WAV pre-generado para un archetype.
 * Devuelve null si el asset no existe (404) o si hay error de red.
 *
 * @param {string} archetypeId
 * @param {string} filename — 'opening.wav' | 'filler_0.wav' … 'filler_3.wav'
 * @returns {Promise<Buffer|null>}
 */
export async function fetchArchetypeAudio(archetypeId, filename) {
  const url = `${BASE_URL}/api/voice/assets/${encodeURIComponent(archetypeId)}/${encodeURIComponent(filename)}`;
  console.debug(`[LaravelClient] fetchArchetypeAudio ${archetypeId}/${filename}`);

  try {
    const res = await fetch(url, { headers: HEADERS });

    if (res.status === 404) {
      console.debug(`[LaravelClient] asset not found: ${archetypeId}/${filename}`);
      return null;
    }

    if (!res.ok) {
      console.warn(`[LaravelClient] fetchArchetypeAudio HTTP ${res.status} for ${filename}`);
      return null;
    }

    const buf = Buffer.from(await res.arrayBuffer());
    console.debug(`[LaravelClient] asset loaded: ${filename} bytes=${buf.length}`);
    return buf;
  } catch (err) {
    console.warn(`[LaravelClient] fetchArchetypeAudio error: ${err.message}`);
    return null;
  }
}

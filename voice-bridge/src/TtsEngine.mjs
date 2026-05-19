/**
 * Motor TTS usando Speechmatics preview TTS REST API.
 * Convierte texto a audio WAV 16 kHz para reproducir en @discordjs/voice.
 *
 * Endpoint: https://preview.tts.speechmatics.com/generate/<voice_id>
 * Voces disponibles: sarah, theo, megan, jack
 * Formato de salida: wav_16000 (WAV + headers, 16 kHz, 16-bit, mono)
 */

const API_KEY = process.env.SPEECHMATICS_API_KEY ?? '';

// Voice ID a usar. Sin voces en español por ahora — sarah funciona con texto es.
const VOICE   = process.env.SPEECHMATICS_VOICE ?? 'sarah';

const TTS_BASE = 'https://preview.tts.speechmatics.com/generate';

/**
 * Sintetiza texto a audio WAV 16 kHz.
 * @param {string} text — Texto a convertir (sin markdown, sin emoji).
 * @returns {Promise<Buffer>} Buffer WAV (16 kHz, 16-bit, mono).
 */
export async function synthesize(text) {
  const url = `${TTS_BASE}/${VOICE}?output_format=wav_16000`;
  const preview = text.length > 60 ? text.slice(0, 60) + '…' : text;

  console.debug(`[TtsEngine] → synth start voice=${VOICE} len=${text.length} text="${preview}"`);
  const t0 = Date.now();

  const res = await fetch(url, {
    method: 'POST',
    headers: {
      Authorization:  `Bearer ${API_KEY}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ text }),
  });

  if (!res.ok) {
    const body = await res.text();
    console.error(`[TtsEngine] ✗ HTTP ${res.status} for "${preview}": ${body}`);
    throw new Error(`TTS failed ${res.status}: ${body}`);
  }

  const arrayBuffer = await res.arrayBuffer();
  const buf = Buffer.from(arrayBuffer);

  console.debug(`[TtsEngine] ✓ synth done in ${Date.now() - t0}ms bytes=${buf.length} voice=${VOICE}`);
  return buf;
}

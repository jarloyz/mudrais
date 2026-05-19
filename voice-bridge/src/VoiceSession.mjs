/**
 * Orquestador principal de una sesión de entrevista de voz.
 *
 * Ciclo por turno:
 *   1. Escucha al usuario con timeout (STT via Speechmatics WebSocket).
 *      - Si no hay audio en LISTEN_TIMEOUT_MS → transcript vacío (silencio).
 *   2. Transcript vacío → incrementa silenceStrikes y dice "¿Sigues ahí?".
 *      - Al tercer strike consecutivo → despedida y cierra.
 *   3. Transcript válido → resetea silenceStrikes → envía a Laravel.
 *   4. Reproduce oraciones del Talkator de a una, chequeando Redis entre cada una.
 *      - Si el Interviewer ya respondió → cancela el stream y lanza la pregunta.
 *      - Si no → reproduce la siguiente oración.
 *   5. Si se agotaron las oraciones sin respuesta → reproduce un filler pre-cacheado
 *      → polling bloqueante (500ms).
 *   6. Reproduce la pregunta del Interviewer → vuelve al paso 1.
 *
 * Optimizaciones de latencia:
 *   - Frases de relleno y saludo se pre-sintetizan en paralelo con la conexión.
 *   - El audio del saludo se sintetiza en paralelo con la preparación de la sesión.
 *   - Fillers pre-cacheados se reproducen durante el polling para ocultar la espera.
 */

import {
  joinVoiceChannel,
  getVoiceConnection,
  EndBehaviorType,
  VoiceConnectionStatus,
  entersState,
} from '@discordjs/voice';
import prism from 'prism-media';
import { SpeechmaticsWs } from './SpeechmaticsWs.mjs';
import { synthesize } from './TtsEngine.mjs';
import { playBuffer } from './AudioPlayer.mjs';
import * as Laravel from './LaravelClient.mjs';
import * as RedisClient from './RedisClient.mjs';

// ── Configuración ──────────────────────────────────────────────────────────

const LISTEN_TIMEOUT_MS   = parseInt(process.env.LISTEN_TIMEOUT_MS ?? '12000', 10);
const STT_FLUSH_MS        = 3_000;
const MAX_SILENCE_STRIKES = 3;

const LINE_END    = /\n/;
const PUNCT_BOUND = /[.?!]\s/;

// ── Strings de silencio / despedida / fillers ─────────────────────────────

const VOICE_PROMPTS = {
  es: {
    greeting:   'Hola, soy Mudrais y estoy aquí para ayudarte a encontrar el mejor emparejamiento. Vamos a empezar con algunas preguntas.',
    stillThere: '¿Sigues ahí?',
    goodbye:    'Ha sido un placer hablar contigo. Hasta pronto.',
    fillers: [
      'Interesante, dame un momento.',
      'Déjame procesar tu respuesta.',
      'Muy bien, un instante.',
      'Entendido, procesando.',
    ],
  },
  en: {
    greeting:   "Hi! I'm Mudrais, and I'm here to help you find your best match. Let's start with a few questions.",
    stillThere: 'Are you still there?',
    goodbye:    'It was a pleasure talking with you. Goodbye.',
    fillers: [
      'Interesting, give me a moment.',
      'Let me process your answer.',
      'Got it, one moment.',
      'Understood, processing.',
    ],
  },
};

// ── Clase principal ────────────────────────────────────────────────────────

export class VoiceSession {
  /** @type {import('@discordjs/voice').VoiceConnection} */
  #connection;
  #sessionId;
  #discordId;
  #locale;
  #active = false;

  /** Buffers WAV pre-generados para los prompts estáticos del sistema. */
  #greetingBuffer   = null;
  #stillThereBuffer = null;
  #goodbyeBuffer    = null;

  /** Buffers TTS pre-sintetizados para reproducir durante el polling. */
  #fillerBuffers = [];

  /**
   * @param {import('discord.js').VoiceChannel} channel
   * @param {string} discordId
   * @param {string} discordGuildId
   * @param {string} locale
   */
  constructor(channel, discordId, discordGuildId, locale = 'es') {
    this.#discordId = discordId;
    this.#locale    = locale;

    this.#connection = joinVoiceChannel({
      channelId: channel.id,
      guildId:   discordGuildId,
      adapterCreator: channel.guild.voiceAdapterCreator,
      selfDeaf: false,
      selfMute: false,
    });
  }

  /**
   * Inicia la sesión:
   *   1. Pre-sintetiza fillers genéricos en paralelo con la conexión de voz.
   *   2. Llama a Laravel para obtener sesión + archetype_id.
   *   3. Descarga assets pre-generados del archetype (opening + fillers contextuales).
   *   4. Si hay assets pre-generados: usa esos; si no: sintetiza genéricos en tiempo real.
   *   5. Reproduce saludo → bucle de turnos.
   */
  async start() {
    this.#active = true;

    console.info(`[VoiceSession] start user=${this.#discordId} locale=${this.#locale}`);
    const tStart = Date.now();

    // Descargar WAVs estáticos del sistema en paralelo con la conexión de voz.
    // Fallback a síntesis en vivo si no están pre-generados (primera ejecución).
    const staticPromise = VoiceSession.#fetchStaticAssets();

    await entersState(this.#connection, VoiceConnectionStatus.Ready, 10_000);
    console.info(`[VoiceSession] voice connection ready in ${Date.now() - tStart}ms`);

    // Obtener sesión de Laravel (devuelve session_id, opening_question, opening_question_en, archetype_id).
    let session_id, opening_question, opening_question_en, archetype_id;
    try {
      const t0 = Date.now();
      ({ session_id, opening_question, opening_question_en, archetype_id } = await Laravel.startSession(
        this.#discordId,
        this.#connection.joinConfig.guildId,
        this.#locale,
      ));
      console.info(`[VoiceSession] Laravel.startSession ok in ${Date.now() - t0}ms session=${session_id} archetype=${archetype_id}`);
    } catch (err) {
      const msg = VoiceSession.#parseApiError(err) ?? VOICE_PROMPTS.en.goodbye;
      console.error(`[VoiceSession] startSession failed: ${err.message}`);
      await this.#say(msg).catch(() => {});
      this.#cleanup();
      return;
    }
    this.#sessionId = session_id;

    // Descargar assets pre-generados del archetype en paralelo con los fillers genéricos.
    // Si el archetype tiene audios guardados: priority. Si no: usar síntesis genérica.
    const tAssets = Date.now();
    const assetFilenames = ['opening.wav', 'filler_0.wav', 'filler_1.wav', 'filler_2.wav', 'filler_3.wav'];
    const assetPromise = archetype_id
      ? Promise.all(assetFilenames.map((f) => Laravel.fetchArchetypeAudio(archetype_id, f)))
      : Promise.resolve(assetFilenames.map(() => null));

    const [
      [greetingBuf, stillThereBuf, goodbyeBuf, ...genericFillerBufs],
      [preOpeningAudio, ...preFillerAudios],
    ] = await Promise.all([staticPromise, assetPromise]);

    this.#greetingBuffer   = greetingBuf;
    this.#stillThereBuffer = stillThereBuf;
    this.#goodbyeBuffer    = goodbyeBuf;

    console.info(`[VoiceSession] assets fetched in ${Date.now() - tAssets}ms pre_opening=${!!preOpeningAudio} pre_fillers=${preFillerAudios.filter(Boolean).length} greeting=${!!this.#greetingBuffer}`);

    // Saludo general fijo (siempre primero, antes de la pregunta de apertura).
    await this.#playStatic(this.#greetingBuffer, VOICE_PROMPTS.en.greeting);

    // Pregunta de apertura: usar el WAV pre-generado si existe; si no, sintetizar en tiempo real.
    let openingAudio;
    if (preOpeningAudio) {
      console.info('[VoiceSession] using pre-generated opening audio');
      openingAudio = preOpeningAudio;
    } else {
      // opening_question_en: traducción al inglés devuelta por Laravel.
      const openingEn = opening_question_en ?? opening_question;
      console.info(`[VoiceSession] pre-generated opening not found — synthesizing live (en="${openingEn.slice(0, 60)}")`);
      openingAudio = await synthesize(openingEn);
    }

    // Fillers: usar los pre-generados contextuales si hay; si no, usar los genéricos estáticos.
    const contextualFillers = preFillerAudios.filter(Boolean);
    const genericFillers    = genericFillerBufs.filter(Boolean);
    this.#fillerBuffers = contextualFillers.length > 0 ? contextualFillers : genericFillers;

    console.info(`[VoiceSession] filler strategy: ${contextualFillers.length > 0 ? 'contextual' : 'generic'} count=${this.#fillerBuffers.length}`);

    await playBuffer(this.#connection, openingAudio);

    let silenceStrikes = 0;

    while (this.#active) {
      // ── Escuchar ─────────────────────────────────────────────────────────
      const tListen = Date.now();
      const transcript = await this.#listenOneTurn();
      console.info(`[VoiceSession] STT done in ${Date.now() - tListen}ms transcript="${transcript.slice(0, 80)}"`);

      // ── Silencio ──────────────────────────────────────────────────────────
      if (!transcript) {
        silenceStrikes++;
        console.info(`[VoiceSession] silence strike ${silenceStrikes}/${MAX_SILENCE_STRIKES}`);

        if (silenceStrikes >= MAX_SILENCE_STRIKES) {
          await this.#playStatic(this.#goodbyeBuffer, VOICE_PROMPTS.en.goodbye);
          break;
        }

        await this.#playStatic(this.#stillThereBuffer, VOICE_PROMPTS.en.stillThere);
        continue;
      }

      silenceStrikes = 0;

      // ── Turno normal ───────────────────────────────────────────────────────
      const tTurn = Date.now();
      const earlyQuestion = await this.#streamTalkatorWithEarlyExit(transcript);
      const nextQuestion  = earlyQuestion ?? await this.#pollUntilReady();
      console.info(`[VoiceSession] turn complete in ${Date.now() - tTurn}ms earlyExit=${earlyQuestion !== null}`);

      if (!nextQuestion || !this.#active) break;

      await this.#say(nextQuestion);
    }

    this.#cleanup();
  }

  /**
   * Detiene la sesión y desconecta del canal.
   */
  stop() {
    this.#active = false;
    this.#cleanup();
  }

  // ── Privados ──────────────────────────────────────────────────────────────

  /**
   * Escucha al usuario durante un turno completo.
   *
   * Pipeline: Discord Opus frames → prism.opus.Decoder → PCM s16le 48kHz stereo
   *           → mezcla L+R a mono → Speechmatics WebSocket.
   *
   * - Suscripción proactiva para capturar paquetes desde el primer momento.
   * - speaking 'start' cancela el wait timer.
   * - AfterSilence(1500ms) cierra el stream → flush STT → done.
   * - Sin ffmpeg en el camino de recepción (evita problemas de container Opus).
   *
   * @returns {Promise<string>}
   */
  async #listenOneTurn() {
    console.debug(`[VoiceSession] listenOneTurn start timeout=${LISTEN_TIMEOUT_MS}ms`);

    const stt = new SpeechmaticsWs();
    await stt.connect();

    return new Promise((resolve) => {
      let finalText   = '';
      let resolved    = false;
      let opusDecoder = null;

      const onSpeakingStart = (userId) => {
        if (userId !== this.#discordId) return;
        clearTimeout(waitTimer);
        console.info(`[VoiceSession] speaking start detected — capturing`);
      };

      const done = (text) => {
        if (resolved) return;
        resolved = true;
        clearTimeout(waitTimer);
        this.#connection.receiver.speaking.off('start', onSpeakingStart);
        stt.destroy();
        userStream.unpipe();
        opusDecoder?.destroy();
        resolve(typeof text === 'string' ? text.trim() : '');
      };

      let waitTimer = setTimeout(() => {
        console.debug('[VoiceSession] listen timeout — no speech detected');
        done('');
      }, LISTEN_TIMEOUT_MS);

      this.#connection.receiver.speaking.on('start', onSpeakingStart);

      const userStream = this.#connection.receiver.subscribe(this.#discordId, {
        end: { behavior: EndBehaviorType.AfterSilence, duration: 1500 },
      });

      // Decodificar raw Opus frames → PCM s16le estéreo 48kHz.
      // prism-media usa opusscript (puro JS) como backend — sin dependencias nativas.
      opusDecoder = new prism.opus.Decoder({ rate: 48000, channels: 2, frameSize: 960 });
      userStream.pipe(opusDecoder);

      opusDecoder.on('data', (stereo) => {
        // Mezclar estéreo → mono: promediar L y R (cada muestra = 2 bytes, cada frame = 4 bytes).
        const mono = Buffer.alloc(stereo.length / 2);
        for (let i = 0; i < mono.length / 2; i++) {
          const l = stereo.readInt16LE(i * 4);
          const r = stereo.readInt16LE(i * 4 + 2);
          mono.writeInt16LE(Math.round((l + r) / 2), i * 2);
        }
        stt.sendAudio(mono);
      });

      opusDecoder.once('end', () => {
        console.debug('[VoiceSession] opus end → STT flush');
        stt.endAudio();
        setTimeout(() => done(finalText), STT_FLUSH_MS);
      });

      opusDecoder.on('error', (err) => {
        console.warn(`[VoiceSession] opus decoder error: ${err.message}`);
        done(finalText);
      });

      stt.on('final', (text) => { finalText += ` ${text}`; });
      stt.on('error', (err) => {
        console.warn(`[VoiceSession] STT error: ${err.message}`);
        done(finalText);
      });
    });
  }

  /**
   * Consume el stream del Talkator oración a oración.
   * Después de cada oración consulta Redis; si hay pregunta cancela el stream.
   *
   * @param {string} transcript
   * @returns {Promise<string|null>}
   */
  async #streamTalkatorWithEarlyExit(transcript) {
    console.debug(`[VoiceSession] Talkator stream start session=${this.#sessionId}`);
    const t0 = Date.now();

    const stream  = await Laravel.postTranscription(this.#sessionId, transcript, this.#discordId);
    const reader  = stream.getReader();
    const decoder = new TextDecoder();

    let buffer       = '';
    let sentenceIdx  = 0;

    const tryFlushLine = async () => {
      let sep = LINE_END.exec(buffer) ?? PUNCT_BOUND.exec(buffer);

      while (sep !== null) {
        const cutAt  = sep.index + (LINE_END.test(sep[0]) ? 0 : 1);
        const phrase = buffer.slice(0, cutAt).trim();
        buffer       = buffer.slice(cutAt + (LINE_END.test(sep[0]) ? 1 : sep[0].length - 1));

        if (phrase) {
          sentenceIdx++;
          console.debug(`[VoiceSession] Talkator sentence #${sentenceIdx}: "${phrase}"`);
          await this.#say(phrase);

          const question = await RedisClient.lpopNextQuestion(this.#sessionId);
          if (question) {
            console.info(`[VoiceSession] early exit after sentence #${sentenceIdx} in ${Date.now() - t0}ms`);
            reader.cancel().catch(() => {});
            return question;
          }
        }

        sep = LINE_END.exec(buffer) ?? PUNCT_BOUND.exec(buffer);
      }

      return undefined;
    };

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;

      buffer += decoder.decode(value, { stream: true });

      const result = await tryFlushLine();
      if (result !== undefined) return result;
    }

    const remaining = buffer.trim();
    if (remaining) {
      sentenceIdx++;
      console.debug(`[VoiceSession] Talkator remaining: "${remaining}"`);
      await this.#say(remaining);

      const question = await RedisClient.lpopNextQuestion(this.#sessionId);
      if (question) return question;
    }

    console.debug(`[VoiceSession] Talkator stream complete in ${Date.now() - t0}ms sentences=${sentenceIdx}`);
    return null;
  }

  /**
   * Reproduce un filler pre-cacheado, luego espera la pregunta del Interviewer via BLPOP.
   * Sin polling HTTP — Redis notifica en cuanto el Job pushea la pregunta.
   * @returns {Promise<string|null>}
   */
  async #pollUntilReady() {
    if (this.#fillerBuffers.length > 0) {
      const idx = Math.floor(Math.random() * this.#fillerBuffers.length);
      console.debug(`[VoiceSession] playing filler #${idx}`);
      await playBuffer(this.#connection, this.#fillerBuffers[idx]).catch(() => {});
    }

    console.debug('[VoiceSession] BLPOP waiting for Interviewer question (max 60s)…');
    const tPoll = Date.now();

    const question = await RedisClient.blpopNextQuestion(this.#sessionId, 60);

    if (question) {
      console.info(`[VoiceSession] question ready after ${Date.now() - tPoll}ms (BLPOP)`);
      return question;
    }

    console.warn('[VoiceSession] BLPOP timeout — Interviewer did not respond in 60s');
    return null;
  }

  /**
   * Sintetiza texto y lo reproduce en el canal de voz. Mide tiempo TTS.
   * @param {string} text
   */
  async #say(text) {
    if (!text) return;
    const t0 = Date.now();
    const audio = await synthesize(text);
    console.debug(`[VoiceSession] #say TTS in ${Date.now() - t0}ms → play`);
    await playBuffer(this.#connection, audio);
    console.debug(`[VoiceSession] #say playback done total=${Date.now() - t0}ms`);
  }

  /**
   * Reproduce un WAV pre-generado si está disponible; si no, sintetiza via TTS como fallback.
   * @param {Buffer|null} buffer
   * @param {string} fallbackText
   */
  async #playStatic(buffer, fallbackText) {
    if (buffer) {
      console.debug(`[VoiceSession] #playStatic using pre-generated WAV (${buffer.length}b)`);
      await playBuffer(this.#connection, buffer).catch(() => {});
    } else {
      console.debug(`[VoiceSession] #playStatic WAV not found — synthesizing: "${fallbackText.slice(0, 40)}"`);
      await this.#say(fallbackText).catch(() => {});
    }
  }

  /**
   * Descarga los WAVs estáticos del sistema desde Laravel.
   * Orden: greeting, still_there, goodbye, generic_filler_0..3
   * @returns {Promise<Array<Buffer|null>>}
   */
  static async #fetchStaticAssets() {
    const files = [
      'greeting.wav', 'still_there.wav', 'goodbye.wav',
      'generic_filler_0.wav', 'generic_filler_1.wav',
      'generic_filler_2.wav', 'generic_filler_3.wav',
    ];
    return Promise.all(files.map((f) => Laravel.fetchArchetypeAudio('static', f)));
  }

  /**
   * Extrae el campo "error" del cuerpo JSON de un error HTTP de la API.
   * @param {Error} err
   * @returns {string|null}
   */
  static #parseApiError(err) {
    try {
      const jsonStart = err.message.indexOf('{');
      if (jsonStart === -1) return null;
      const parsed = JSON.parse(err.message.slice(jsonStart));
      return typeof parsed.error === 'string' ? parsed.error : null;
    } catch {
      return null;
    }
  }

  #cleanup() {
    try {
      const existing = getVoiceConnection(this.#connection.joinConfig.guildId);
      existing?.destroy();
    } catch {
      // Ya desconectado.
    }
  }
}

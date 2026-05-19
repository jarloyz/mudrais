/**
 * Cliente WebSocket de Speechmatics para STT en tiempo real.
 * Emite eventos 'partial' y 'final' con el transcript.
 * Emite 'error' y 'close' para gestión de ciclo de vida.
 */

import { EventEmitter } from 'node:events';
import WebSocket from 'ws';

const API_KEY  = process.env.SPEECHMATICS_API_KEY ?? '';
const LANGUAGE = process.env.SPEECHMATICS_LANGUAGE ?? 'es';

const WS_URL = 'wss://eu2.rt.speechmatics.com/v2';

const START_RECOGNITION = {
  message: 'StartRecognition',
  audio_format: {
    type: 'raw',
    encoding: 'pcm_s16le',
    sample_rate: 48000,
  },
  transcription_config: {
    language: LANGUAGE,
    operating_point: 'enhanced',
    enable_partials: true,
    max_delay: 2,
  },
};

export class SpeechmaticsWs extends EventEmitter {
  /** @type {WebSocket|null} */
  #ws = null;
  #connected = false;
  #audioBytesSent = 0;
  #connectAt = 0;

  /**
   * Abre la sesión WebSocket y envía StartRecognition.
   * Usa Authorization: Bearer (no ?jwt=) para autenticar con API key.
   * @returns {Promise<void>} Resuelve cuando Speechmatics confirma RecognitionStarted.
   */
  connect() {
    this.#connectAt = Date.now();
    console.debug(`[SpeechmaticsWs] → connecting to ${WS_URL} lang=${LANGUAGE}`);

    return new Promise((resolve, reject) => {
      this.#ws = new WebSocket(WS_URL, {
        headers: { Authorization: `Bearer ${API_KEY}` },
      });

      this.#ws.once('open', () => {
        console.debug('[SpeechmaticsWs] WS open → sending StartRecognition');
        this.#ws.send(JSON.stringify(START_RECOGNITION));
      });

      this.#ws.on('message', (raw) => {
        let msg;
        try {
          msg = JSON.parse(raw.toString());
        } catch {
          return;
        }

        switch (msg.message) {
          case 'RecognitionStarted':
            this.#connected = true;
            console.debug(`[SpeechmaticsWs] ✓ RecognitionStarted in ${Date.now() - this.#connectAt}ms`);
            resolve();
            break;

          case 'AddPartialTranscript': {
            const text = msg.results?.map((r) => r.alternatives?.[0]?.content ?? '').join('').trim();
            if (text) {
              console.debug(`[SpeechmaticsWs] partial: "${text}"`);
              this.emit('partial', text);
            }
            break;
          }

          case 'AddTranscript': {
            const text = msg.results?.map((r) => r.alternatives?.[0]?.content ?? '').join('').trim();
            if (text) {
              console.info(`[SpeechmaticsWs] final: "${text}"`);
              this.emit('final', text);
            }
            break;
          }

          case 'EndOfTranscript':
            console.debug(`[SpeechmaticsWs] EndOfTranscript totalBytesSent=${this.#audioBytesSent}`);
            this.emit('close');
            break;

          case 'Error': {
            const err = new Error(`Speechmatics WS error: ${msg.reason ?? JSON.stringify(msg)}`);
            console.error(`[SpeechmaticsWs] ✗ protocol error connected=${this.#connected}: ${err.message}`);
            if (!this.#connected) reject(err);
            else this.emit('error', err);
            break;
          }

          default:
            console.debug(`[SpeechmaticsWs] msg ignored: ${msg.message}`);
        }
      });

      this.#ws.on('error', (err) => {
        console.error(`[SpeechmaticsWs] ✗ ws error connected=${this.#connected}: ${err.message}`);
        if (!this.#connected) reject(err);
        else this.emit('error', err);
      });

      this.#ws.on('close', (code, reason) => {
        console.debug(`[SpeechmaticsWs] WS closed code=${code} connected=${this.#connected}`);
        if (!this.#connected) reject(new Error(`WS closed before RecognitionStarted (${code})`));
        else this.emit('close');
      });
    });
  }

  /**
   * Envía un chunk de audio PCM s16le al WebSocket.
   * @param {Buffer} chunk
   */
  sendAudio(chunk) {
    if (this.#ws?.readyState === WebSocket.OPEN) {
      this.#audioBytesSent += chunk.length;
      this.#ws.send(chunk);
    }
  }

  /**
   * Señala fin de audio y espera EndOfTranscript.
   */
  endAudio() {
    if (this.#ws?.readyState === WebSocket.OPEN) {
      console.debug(`[SpeechmaticsWs] EndOfStream → totalBytesSent=${this.#audioBytesSent}`);
      this.#ws.send(JSON.stringify({ message: 'EndOfStream', last_seq_no: 0 }));
    }
  }

  /**
   * Cierra el WebSocket inmediatamente.
   */
  destroy() {
    this.#ws?.terminate();
    this.#ws = null;
    this.#connected = false;
  }
}

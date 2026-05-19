/**
 * Reproduce un buffer de audio WAV en un canal de voz de Discord.
 * Usa @discordjs/voice AudioPlayer + createAudioResource.
 *
 * StreamType.Arbitrary → @discordjs/voice invoca ffmpeg internamente para
 * transcodificar cualquier formato (WAV 16kHz, etc.) a Opus para Discord.
 */

import {
  createAudioResource,
  AudioPlayerStatus,
  createAudioPlayer,
  StreamType,
} from '@discordjs/voice';
import { Readable } from 'node:stream';

/**
 * Reproduce un buffer de audio en una VoiceConnection y espera a que termine.
 * @param {import('@discordjs/voice').VoiceConnection} connection
 * @param {Buffer} audioBuffer — Buffer WAV (cualquier sample rate) con cabeceras
 * @returns {Promise<void>}
 */
export function playBuffer(connection, audioBuffer) {
  return new Promise((resolve, reject) => {
    const readable = Readable.from(audioBuffer);
    const resource = createAudioResource(readable, {
      inputType: StreamType.Arbitrary,
    });

    const player = createAudioPlayer();
    connection.subscribe(player);

    player.on(AudioPlayerStatus.Idle, () => resolve());
    player.on('error', reject);

    player.play(resource);
  });
}

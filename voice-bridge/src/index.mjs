/**
 * Entry point del voice-bridge.
 * Conecta el bot Gamma a Discord y pollea /api/voice/pending-start
 * para crear canales de voz privados temporales cuando el usuario
 * usa /voice-interview.
 *
 * Flujo FASE 5:
 *   1. /voice-interview → Laravel → type:5 deferred ephemeral + Redis
 *   2. pollPendingStart() consume {discord_id, guild_id, locale, interaction_token}
 *   3. handlePendingSession() crea canal privado + edita el deferred con embed
 *   4. voiceStateUpdate detecta que el usuario entró al canal → inicia VoiceSession
 *   5. session.start().finally(() => channel.delete())
 */

import 'dotenv/config';
import {
  Client,
  GatewayIntentBits,
  ChannelType,
  PermissionFlagsBits,
  REST,
  Routes,
  SlashCommandBuilder,
} from 'discord.js';
import { VoiceSession } from './VoiceSession.mjs';
import * as Laravel from './LaravelClient.mjs';
import * as RedisClient from './RedisClient.mjs';

const TOKEN  = process.env.DISCORD_BOT_TOKEN_GAMMA ?? '';
const APP_ID = process.env.DISCORD_APP_ID_GAMMA ?? '';
const LOG    = process.env.LOG_LEVEL === 'debug'
  ? console.debug.bind(console)
  : () => {};

const CHANNEL_TIMEOUT_MS  = 5 * 60 * 1000; // 5 minutos

// Embed color azul Discord
const EMBED_COLOR = 3_447_003;

const INTERVIEW_STRINGS = {
  es: {
    channelName: '🎙️-entrevista',
    embedTitle:  '🎙️ Tu sala está lista',
    embedDesc:   (id) => `Únete a <#${id}> para comenzar tu entrevista de voz.`,
  },
  en: {
    channelName: '🎙️-interview',
    embedTitle:  '🎙️ Your room is ready',
    embedDesc:   (id) => `Join <#${id}> to start your voice interview.`,
  },
};

// ── Registro del slash command ─────────────────────────────────────────────

const command = new SlashCommandBuilder()
  .setName('voice-interview')
  .setNameLocalization('es-ES', 'entrevista-voz')
  .setDescription('Start a voice profile interview.')
  .setDescriptionLocalization('es-ES', 'Inicia una entrevista de perfil por voz.')
  .toJSON();

async function registerCommands() {
  const rest = new REST({ version: '10' }).setToken(TOKEN);
  await rest.put(Routes.applicationCommands(APP_ID), { body: [command] });
  console.info('[voice-bridge] Slash commands registered.');
}

// ── Mapas de estado ────────────────────────────────────────────────────────

/** @type {Map<string, VoiceSession>} channelId → active session */
const activeSessions = new Map();

/**
 * Canales de entrevista pendientes de que el usuario entre.
 * @type {Map<string, {discordId: string, locale: string, guildId: string, cleanupTimer: NodeJS.Timeout}>}
 */
const interviewChannels = new Map();

// ── REST client para editar la respuesta diferida ─────────────────────────

const rest = new REST({ version: '10' }).setToken(TOKEN);

// ── Cliente Discord ────────────────────────────────────────────────────────

const client = new Client({
  intents: [
    GatewayIntentBits.Guilds,
    GatewayIntentBits.GuildVoiceStates,
  ],
});

client.once('clientReady', async () => {
  console.info(`[voice-bridge] Logged in as ${client.user.tag}`);

  await registerCommands().catch((err) =>
    console.error('[voice-bridge] Command registration failed:', err),
  );

  startPendingSessionsLoop();
});

// ── voiceStateUpdate ───────────────────────────────────────────────────────

client.on('voiceStateUpdate', (oldState, newState) => {
  const userId = newState.member?.id ?? oldState.member?.id;
  if (!userId) return;

  // ── Join branch: usuario entra a un canal de entrevista privado ────────
  if (newState.channelId && interviewChannels.has(newState.channelId)) {
    const entry = interviewChannels.get(newState.channelId);

    if (entry.discordId === userId) {
      const channelId = newState.channelId;
      const channel   = newState.channel;

      clearTimeout(entry.cleanupTimer);
      interviewChannels.delete(channelId);

      if (activeSessions.has(userId)) {
        // Ya tiene sesión activa (edge case): limpiar el canal y salir
        console.warn(`[index] user=${userId} already has active session — deleting channel`);
        channel?.delete().catch(() => {});
        return;
      }

      console.info(`[index] user=${userId} joined interview channel=${channelId} locale=${entry.locale} — starting session`);

      const session = new VoiceSession(channel, userId, entry.guildId, entry.locale);
      activeSessions.set(userId, session);

      session.start()
        .catch((err) => console.error(`[voice-bridge] Session error user=${userId}:`, err))
        .finally(() => {
          activeSessions.delete(userId);
          channel?.delete().catch(() => {});
          console.info(`[index] session ended, channel deleted for user=${userId}`);
        });
    }
    return;
  }

  // ── Leave branch: usuario abandona un canal con sesión activa ──────────
  const leftChannel = oldState.channelId && !newState.channelId;
  if (leftChannel && activeSessions.has(userId)) {
    console.info(`[index] user=${userId} left voice channel — stopping session`);
    activeSessions.get(userId).stop();
    activeSessions.delete(userId);
  }
});

// ── Loop BLPOP de sesiones pendientes ─────────────────────────────────────
// Sin polling HTTP — Redis notifica en cuanto Laravel encole una señal.

async function startPendingSessionsLoop() {
  console.info('[index] pending-start BLPOP loop started');

  while (true) {
    try {
      const payload = await RedisClient.blpopPendingStart(30);

      if (!payload) {
        // Timeout de 30s: heartbeat para confirmar que el loop sigue vivo.
        console.debug(`[index] BLPOP heartbeat — sessions=${activeSessions.size} pending_channels=${interviewChannels.size}`);
        continue;
      }

      const { discord_id, guild_id, locale, interaction_token, app_id, created_at } = payload;

      // Descartar señales con token vencido (Discord interaction tokens duran 15 min).
      const ageSeconds = Date.now() / 1000 - (created_at ?? 0);
      if (ageSeconds > 13 * 60) {
        console.warn(`[index] Token vencido (${Math.round(ageSeconds)}s), descartando señal user=${discord_id}`);
        continue;
      }

      console.info(`[index] Pending session → user=${discord_id} guild=${guild_id} locale=${locale}`);
      await handlePendingSession(
        discord_id,
        guild_id,
        locale ?? 'es',
        interaction_token ?? '',
        app_id || APP_ID,
      );
    } catch (err) {
      console.error('[voice-bridge] Error en BLPOP pending-start:', err.message);
      // Pausa breve para no hacer busy-loop si Redis está caído
      await new Promise((r) => setTimeout(r, 2000));
    }
  }
}

/**
 * Crea un canal de voz privado para la entrevista y edita la respuesta
 * diferida de Discord con un embed efímero que invita al usuario a unirse.
 *
 * @param {string} discordId
 * @param {string} guildId
 * @param {string} locale
 * @param {string} interactionToken
 * @param {string} appId  — application_id del bot que recibió la interacción
 */
async function handlePendingSession(discordId, guildId, locale, interactionToken, appId = APP_ID) {
  // Guard: ya tiene sesión de voz activa
  if (activeSessions.has(discordId)) {
    LOG(`[index] Session already active for user=${discordId}, skipping`);
    return;
  }

  // Guard: ya tiene un canal de entrevista pendiente
  for (const entry of interviewChannels.values()) {
    if (entry.discordId === discordId) {
      LOG(`[index] Interview channel already pending for user=${discordId}, skipping`);
      return;
    }
  }

  let guild;
  try {
    guild = await client.guilds.fetch(guildId);
  } catch {
    console.error(`[index] Guild not found: ${guildId}`);
    return;
  }

  let member;
  try {
    member = await guild.members.fetch(discordId);
  } catch {
    console.error(`[index] Member not found: user=${discordId} guild=${guildId}`);
    return;
  }

  const strings = INTERVIEW_STRINGS[locale] ?? INTERVIEW_STRINGS.es;
  let channel   = null;

  try {
    channel = await guild.channels.create({
      name: strings.channelName,
      type: ChannelType.GuildVoice,
      permissionOverwrites: [
        {
          // @everyone — sin acceso
          id:   guild.roles.everyone.id,
          deny: [PermissionFlagsBits.ViewChannel],
        },
        {
          // El usuario — puede ver y conectarse
          id:    discordId,
          allow: [PermissionFlagsBits.ViewChannel, PermissionFlagsBits.Connect],
        },
        {
          // El bot — puede ver, conectarse y hablar
          id:    client.user.id,
          allow: [
            PermissionFlagsBits.ViewChannel,
            PermissionFlagsBits.Connect,
            PermissionFlagsBits.Speak,
          ],
        },
      ],
    });

    console.info(`[index] Created interview channel=${channel.id} for user=${discordId}`);

    // Editar la respuesta diferida con el embed de invitación.
    // appId es el application_id del bot que recibió la interacción original
    // (puede ser Alpha, Beta o Gamma según desde dónde se lanzó el comando).
    await rest.patch(Routes.webhookMessage(appId, interactionToken), {
      body: {
        content: '',
        flags:   64,
        embeds:  [
          {
            title:       strings.embedTitle,
            description: strings.embedDesc(channel.id),
            color:       EMBED_COLOR,
          },
        ],
      },
    });

    console.info(`[index] Deferred message edited for user=${discordId}`);

    // Timer de limpieza: si el usuario no entra en 5 min, borrar canal
    const cleanupTimer = setTimeout(async () => {
      if (interviewChannels.has(channel.id)) {
        interviewChannels.delete(channel.id);
        LOG(`[index] Cleanup timer fired — deleting channel=${channel.id}`);
        await channel.delete().catch(() => {});
      }
    }, CHANNEL_TIMEOUT_MS);

    interviewChannels.set(channel.id, {
      discordId,
      locale,
      guildId,
      cleanupTimer,
    });
  } catch (err) {
    console.error(`[voice-bridge] handlePendingSession error for user=${discordId}:`, err);
    // Si el canal ya fue creado, limpiarlo para no dejar canales huérfanos
    if (channel) {
      await channel.delete().catch(() => {});
    }
  }
}

client.login(TOKEN).catch((err) => {
  console.error('[voice-bridge] Login failed:', err);
  process.exit(1);
});

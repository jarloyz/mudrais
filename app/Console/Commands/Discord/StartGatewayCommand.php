<?php

namespace App\Console\Commands\Discord;

use App\Jobs\Discord\ProcessGatewayMessageJob;
use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class StartGatewayCommand extends Command
{
    protected $signature = 'discord:gateway {bot : Slug del bot (alpha|beta|gamma)}';
    protected $description = 'Arranca el daemon de Gateway WebSocket para el bot indicado.';

    public function handle(): int
    {
        $slug  = $this->argument('bot');
        $token = $this->resolveToken($slug);

        if (! $token) {
            $this->error("No se encontró bot_token para el slug '{$slug}' en config/services.php.");
            return self::FAILURE;
        }

        Log::info("[StartGatewayCommand] Iniciando Gateway para bot '{$slug}'.");

        $discord = new Discord([
            'token'   => $token,
            'intents' => Intents::getDefaultIntents()
                | Intents::GUILD_MESSAGES
                | Intents::MESSAGE_CONTENT,
            'logger'  => $this->buildLogger($slug),
        ]);

        $discord->on(Event::MESSAGE_CREATE, function (Message $message) use ($slug) {
            if ($message->author?->bot) {
                return;
            }

            if ((int) $message->channel->type !== Channel::TYPE_PRIVATE_THREAD) {
                return;
            }

            $threadId = $message->channel_id;

            if (! Cache::has("thread_session_{$threadId}")) {
                return;
            }

            Log::debug("[StartGatewayCommand:{$slug}] Mensaje recibido en hilo con sesión activa.", [
                'thread_id' => $threadId,
                'user_id'   => $message->author?->id,
            ]);

            ProcessGatewayMessageJob::dispatch(
                guildId:     $message->guild_id,
                threadId:    $threadId,
                discordId:   $message->author->id,
                username:    $message->author->username ?? null,
                textContent: $message->content ?: null,
                audioUrl:    $this->extractAudioUrl($message),
            );
        });

        $discord->on('ready', function (Discord $discord) use ($slug) {
            Log::info("[StartGatewayCommand:{$slug}] Conectado al Discord Gateway. Bot: {$discord->user->username}.");
            $this->info("Bot '{$slug}' conectado como: {$discord->user->username}");
        });

        $discord->run();

        return self::SUCCESS;
    }

    private function resolveToken(string $slug): ?string
    {
        $bots = config('services.discord.bots', []);

        foreach ($bots as $bot) {
            if (($bot['slug'] ?? '') === $slug && ! empty($bot['bot_token'])) {
                return $bot['bot_token'];
            }
        }

        return null;
    }

    private function extractAudioUrl(Message $message): ?string
    {
        foreach ($message->attachments as $attachment) {
            $contentType = $attachment->content_type ?? '';
            if (str_starts_with($contentType, 'audio/')) {
                return $attachment->url;
            }
        }

        return null;
    }

    private function buildLogger(string $slug): Logger
    {
        $logger = new Logger("discord-gateway-{$slug}");
        $logger->pushHandler(new StreamHandler('php://stdout', \Monolog\Level::Debug));

        return $logger;
    }
}

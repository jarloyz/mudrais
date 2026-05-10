<?php

namespace App\Support;

final class LogPreview
{
    public static function text(string $value, int $limit = 4000): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).'...';
    }

    /**
     * @param mixed $value
     */
    public static function json(mixed $value, int $limit = 12000): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return self::text($encoded ?: '', $limit);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array{role:string,content:string,content_chars:int}>
     */
    public static function messages(array $messages, int $limit = 4000): array
    {
        return array_map(static function (array $message) use ($limit): array {
            $content = (string) ($message['content'] ?? '');

            return [
                'role' => (string) ($message['role'] ?? ''),
                'content' => self::text($content, $limit),
                'content_chars' => mb_strlen($content),
            ];
        }, $messages);
    }
}

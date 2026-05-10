<?php

namespace Tests\Unit\Support;

use App\Support\LogPreview;
use Tests\TestCase;

class LogPreviewTest extends TestCase
{
    public function test_text_truncates_long_values(): void
    {
        $value = str_repeat('a', 20);

        $this->assertSame('aaaaa...', LogPreview::text($value, 5));
    }

    public function test_json_returns_pretty_preview(): void
    {
        $preview = LogPreview::json(['scene' => 'demo', 'ok' => true], 1000);

        $this->assertStringContainsString('"scene": "demo"', $preview);
        $this->assertStringContainsString('"ok": true', $preview);
    }

    public function test_messages_returns_role_and_char_count(): void
    {
        $preview = LogPreview::messages([
            ['role' => 'user', 'content' => 'Hola'],
        ], 1000);

        $this->assertSame('user', $preview[0]['role']);
        $this->assertSame('Hola', $preview[0]['content']);
        $this->assertSame(4, $preview[0]['content_chars']);
    }
}

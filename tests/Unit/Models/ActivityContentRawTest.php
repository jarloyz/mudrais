<?php

namespace Tests\Unit\Models;

use App\Domains\Narrative\Models\Activity;
use App\Domains\Narrative\Models\Avatar;
use PHPUnit\Framework\TestCase;

class ActivityContentRawTest extends TestCase
{
    public function test_activity_content_raw_cast(): void
    {
        $activity = new Activity();
        $activity->fill([
            'content_raw' => ['foo' => 'bar'],
            'semantic_tag_query' => 'test tags'
        ]);

        $this->assertEquals(['foo' => 'bar'], $activity->content_raw);
        $this->assertEquals('test tags', $activity->semantic_tag_query);
    }

    public function test_avatar_content_raw_cast(): void
    {
        $avatar = new Avatar();
        $avatar->fill([
            'content_raw' => ['baz' => 'qux'],
            'semantic_tag_query' => 'avatar tags'
        ]);

        $this->assertEquals(['baz' => 'qux'], $avatar->content_raw);
        $this->assertEquals('avatar tags', $avatar->semantic_tag_query);
    }
}

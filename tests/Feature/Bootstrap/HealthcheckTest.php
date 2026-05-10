<?php

namespace Tests\Feature\Bootstrap;

use Tests\TestCase;

class HealthcheckTest extends TestCase
{
    public function test_root_endpoint_describes_bootstrap(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'bootstrapped')
            ->assertJsonPath('docs.architecture', 'docs/architecture.md');
    }

    public function test_api_health_endpoint_returns_ok_status(): void
    {
        $response = $this->get('/api/health');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('runtime', 'laravel');
    }
}

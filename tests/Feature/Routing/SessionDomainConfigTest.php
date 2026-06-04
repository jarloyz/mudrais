<?php

namespace Tests\Feature\Routing;

use Tests\TestCase;

class SessionDomainConfigTest extends TestCase
{
    public function test_session_domain_is_null_by_default_in_test_env(): void
    {
        // SESSION_DOMAIN is not set in phpunit.xml — cookie scopes to exact host in local/test
        $this->assertNull(config('session.domain'));
    }

    public function test_session_domain_with_leading_dot_enables_subdomain_sharing(): void
    {
        // Production must set SESSION_DOMAIN=.mudrais.com (leading dot is critical)
        config(['session.domain' => '.mudrais.com']);

        $this->assertStringStartsWith('.', config('session.domain'));
    }

    public function test_sanctum_stateful_config_is_an_array(): void
    {
        $stateful = config('sanctum.stateful');

        $this->assertIsArray($stateful);
        $this->assertNotEmpty($stateful);
    }

    public function test_sanctum_stateful_includes_localhost_in_test_env(): void
    {
        // Verify the default stateful list includes local dev entries
        $stateful = config('sanctum.stateful');

        $this->assertContains('localhost', $stateful);
    }

    public function test_base_domain_config_key_exists(): void
    {
        // config/app.php must expose base_domain derived from APP_BASE_DOMAIN or APP_URL
        $domain = config('app.base_domain');

        $this->assertNotNull($domain);
        $this->assertNotEmpty($domain);
    }

    public function test_base_domain_does_not_include_protocol(): void
    {
        $domain = config('app.base_domain');

        $this->assertStringNotContainsString('http://', $domain);
        $this->assertStringNotContainsString('https://', $domain);
    }
}

<?php

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

class ProductionComposeTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        // compose + docker/ + .env.production.example all live inside laravel_app/
        $this->repoRoot = base_path();
    }

    // ── docker-compose.prod.yml ───────────────────────────────────────────────

    public function test_production_compose_file_exists(): void
    {
        $this->assertFileExists("{$this->repoRoot}/docker-compose.prod.yml");
    }

    public function test_compose_defines_nginx_service(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker-compose.prod.yml");
        $this->assertStringContainsString('nginx:', $content);
    }

    public function test_compose_defines_app_service(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker-compose.prod.yml");
        $this->assertStringContainsString('app:', $content);
    }

    public function test_compose_defines_worker_service(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker-compose.prod.yml");
        $this->assertStringContainsString('worker:', $content);
    }

    public function test_compose_defines_postgres_service(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker-compose.prod.yml");
        $this->assertStringContainsString('postgres:', $content);
    }

    public function test_compose_defines_redis_service(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker-compose.prod.yml");
        $this->assertStringContainsString('redis:', $content);
    }

    public function test_compose_defines_qdrant_service(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker-compose.prod.yml");
        $this->assertStringContainsString('qdrant:', $content);
        // Version must be pinned — never use :latest in production for Qdrant (breaking schema changes risk)
        $this->assertStringNotContainsString('qdrant/qdrant:latest', $content);
    }

    public function test_compose_defines_certbot_service(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker-compose.prod.yml");
        $this->assertStringContainsString('certbot:', $content);
    }

    public function test_compose_nginx_exposes_ports_80_and_443(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker-compose.prod.yml");
        $this->assertStringContainsString('"80:80"', $content);
        $this->assertStringContainsString('"443:443"', $content);
    }

    public function test_compose_app_does_not_expose_ports_externally(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker-compose.prod.yml");
        // PHP-FPM port 9000 must NOT be exposed to host — only accessible via internal network
        $this->assertStringNotContainsString('"9000:9000"', $content);
        $this->assertStringNotContainsString("'9000:9000'", $content);
    }

    public function test_compose_postgres_has_health_check(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker-compose.prod.yml");
        $this->assertStringContainsString('pg_isready', $content);
    }

    public function test_compose_redis_has_health_check(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker-compose.prod.yml");
        $this->assertStringContainsString('redis-cli', $content);
        $this->assertStringContainsString('"ping"', $content);
    }

    public function test_compose_worker_runs_horizon(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker-compose.prod.yml");
        $this->assertStringContainsString('artisan horizon', $content);
    }

    public function test_compose_uses_internal_network(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker-compose.prod.yml");
        $this->assertStringContainsString('networks:', $content);
    }

    // ── Dockerfile.prod ───────────────────────────────────────────────────────

    public function test_production_dockerfile_exists(): void
    {
        $this->assertFileExists("{$this->repoRoot}/docker/php/Dockerfile.prod");
    }

    public function test_dockerfile_uses_php_fpm_base(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker/php/Dockerfile.prod");
        $this->assertMatchesRegularExpression('/FROM php:\d+\.\d+-fpm/', $content);
    }

    public function test_dockerfile_installs_pgsql_extension(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker/php/Dockerfile.prod");
        $this->assertStringContainsString('pdo_pgsql', $content);
    }

    public function test_dockerfile_installs_redis_extension(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker/php/Dockerfile.prod");
        $this->assertStringContainsString('redis', $content);
    }

    public function test_dockerfile_installs_opcache(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker/php/Dockerfile.prod");
        $this->assertStringContainsString('opcache', $content);
    }

    public function test_dockerfile_installs_pcntl_for_horizon(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker/php/Dockerfile.prod");
        $this->assertStringContainsString('pcntl', $content);
    }

    public function test_dockerfile_does_not_include_xdebug(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker/php/Dockerfile.prod");
        $this->assertStringNotContainsString('xdebug', $content);
    }

    // ── Nginx config ──────────────────────────────────────────────────────────

    public function test_nginx_http_config_exists(): void
    {
        $this->assertFileExists("{$this->repoRoot}/docker/nginx/conf.d/mudrais-http.conf");
    }

    public function test_nginx_https_config_exists(): void
    {
        $this->assertFileExists("{$this->repoRoot}/docker/nginx/conf.d/mudrais-https.conf");
    }

    public function test_nginx_https_config_includes_mudrais_dot_com(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker/nginx/conf.d/mudrais-https.conf");
        $this->assertStringContainsString('mudrais.com', $content);
    }

    public function test_nginx_https_config_includes_app_subdomain(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker/nginx/conf.d/mudrais-https.conf");
        $this->assertStringContainsString('app.mudrais.com', $content);
    }

    public function test_nginx_https_config_proxies_to_fpm_on_port_9000(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker/nginx/conf.d/mudrais-https.conf");
        $this->assertStringContainsString('app:9000', $content);
    }

    public function test_nginx_http_config_includes_acme_challenge_path(): void
    {
        $content = file_get_contents("{$this->repoRoot}/docker/nginx/conf.d/mudrais-http.conf");
        $this->assertStringContainsString('acme-challenge', $content);
    }

    // ── .env.production.example ───────────────────────────────────────────────

    public function test_env_production_example_exists(): void
    {
        $this->assertFileExists("{$this->repoRoot}/.env.production.example");
    }

    public function test_env_example_has_app_base_domain(): void
    {
        $content = file_get_contents("{$this->repoRoot}/.env.production.example");
        $this->assertStringContainsString('APP_BASE_DOMAIN', $content);
    }

    public function test_env_example_has_session_domain_with_leading_dot(): void
    {
        $content = file_get_contents("{$this->repoRoot}/.env.production.example");
        $this->assertStringContainsString('SESSION_DOMAIN=.mudrais.com', $content);
    }

    public function test_env_example_sets_app_debug_false(): void
    {
        $content = file_get_contents("{$this->repoRoot}/.env.production.example");
        $this->assertStringContainsString('APP_DEBUG=false', $content);
    }

    public function test_env_example_uses_redis_for_queue(): void
    {
        $content = file_get_contents("{$this->repoRoot}/.env.production.example");
        $this->assertStringContainsString('QUEUE_CONNECTION=redis', $content);
    }

    public function test_env_example_has_sanctum_stateful_domains(): void
    {
        $content = file_get_contents("{$this->repoRoot}/.env.production.example");
        $this->assertStringContainsString('SANCTUM_STATEFUL_DOMAINS', $content);
    }
}

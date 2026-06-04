<?php

namespace Tests\Feature\Routing;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SubdomainRoutingTest extends TestCase
{
    private string $domain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = config('app.base_domain');
    }

    public function test_discord_oauth_redirect_is_constrained_to_app_subdomain(): void
    {
        $route = Route::getRoutes()->getByName('auth.discord.redirect');

        $this->assertNotNull($route, 'Route auth.discord.redirect must be registered');
        $this->assertSame("app.{$this->domain}", $route->getDomain());
    }

    public function test_discord_oauth_callback_is_constrained_to_app_subdomain(): void
    {
        $route = Route::getRoutes()->getByName('auth.discord.callback');

        $this->assertNotNull($route);
        $this->assertSame("app.{$this->domain}", $route->getDomain());
    }

    public function test_discord_beta_oauth_routes_are_constrained_to_app_subdomain(): void
    {
        $redirect = Route::getRoutes()->getByName('auth.discord-beta.redirect');
        $callback = Route::getRoutes()->getByName('auth.discord-beta.callback');

        $this->assertNotNull($redirect);
        $this->assertNotNull($callback);
        $this->assertSame("app.{$this->domain}", $redirect->getDomain());
        $this->assertSame("app.{$this->domain}", $callback->getDomain());
    }

    public function test_bot_invite_routes_are_constrained_to_app_subdomain(): void
    {
        $redirect = Route::getRoutes()->getByName('invite.bot.redirect');
        $callback = Route::getRoutes()->getByName('invite.bot.callback');

        $this->assertNotNull($redirect);
        $this->assertNotNull($callback);
        $this->assertSame("app.{$this->domain}", $redirect->getDomain());
        $this->assertSame("app.{$this->domain}", $callback->getDomain());
    }

    public function test_discord_gamma_oauth_routes_are_constrained_to_app_subdomain(): void
    {
        $redirect = Route::getRoutes()->getByName('auth.discord-gamma.redirect');
        $callback = Route::getRoutes()->getByName('auth.discord-gamma.callback');

        $this->assertNotNull($redirect);
        $this->assertNotNull($callback);
        $this->assertSame("app.{$this->domain}", $redirect->getDomain());
        $this->assertSame("app.{$this->domain}", $callback->getDomain());
    }

    public function test_bot_gamma_invite_routes_are_constrained_to_app_subdomain(): void
    {
        $redirect = Route::getRoutes()->getByName('invite.bot-gamma.redirect');
        $callback = Route::getRoutes()->getByName('invite.bot-gamma.callback');

        $this->assertNotNull($redirect);
        $this->assertNotNull($callback);
        $this->assertSame("app.{$this->domain}", $redirect->getDomain());
        $this->assertSame("app.{$this->domain}", $callback->getDomain());
    }

    public function test_discord_interactions_webhook_has_no_domain_constraint(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn($r) => str_contains($r->uri(), 'discord/interactions'));

        $this->assertNotNull($route, 'Discord interactions route must be registered');
        $this->assertEmpty($route->getDomain());
    }

    public function test_api_health_endpoint_has_no_domain_constraint(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn($r) => $r->uri() === 'api/health');

        $this->assertNotNull($route, 'api/health route must be registered');
        $this->assertEmpty($route->getDomain());
    }

    public function test_oauth_route_returns_404_when_accessed_on_root_domain(): void
    {
        $response = $this->get("http://{$this->domain}/auth/discord/redirect");

        $response->assertNotFound();
    }

    public function test_bot_invite_returns_404_when_accessed_on_root_domain(): void
    {
        $response = $this->get("http://{$this->domain}/invite/bot/redirect");

        $response->assertNotFound();
    }
}

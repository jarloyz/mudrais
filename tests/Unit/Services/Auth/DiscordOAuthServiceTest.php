<?php

namespace Tests\Unit\Services\Auth;

use App\Domains\Community\Models\Player;
use App\Services\Auth\DiscordOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class DiscordOAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DiscordOAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DiscordOAuthService();
    }

    public function test_creates_new_player_successfully()
    {
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('info')->once();

        $socialUser = new SocialiteUser();
        $socialUser->id = '111222333';
        $socialUser->nickname = 'NewPlayer';
        $socialUser->name = 'Name';

        $player = $this->service->authenticateOrRegister($socialUser);

        $this->assertInstanceOf(Player::class, $player);
        $this->assertEquals('111222333', $player->discord_id);
        $this->assertEquals('NewPlayer', $player->username);
    }

    public function test_returns_existing_player_and_updates()
    {
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('info')->once();

        $existingPlayer = Player::create([
            'discord_id' => '444555666',
            'username' => 'OldNick',
            'last_active_at' => now(),
        ]);

        $socialUser = new SocialiteUser();
        $socialUser->id = '444555666';
        $socialUser->nickname = 'UpdatedNick';

        $player = $this->service->authenticateOrRegister($socialUser);

        $this->assertEquals($existingPlayer->id, $player->id);
        $this->assertEquals('UpdatedNick', $player->username);
    }

    public function test_logs_error_on_db_exception()
    {
        Log::shouldReceive('debug')->once();
        Log::shouldReceive('error')->once();

        $socialUser = new SocialiteUser();
        $socialUser->id = '999999999';
        $socialUser->nickname = 'ErrorPlayer';

        // Force a DB exception by setting discord_id to a very long string that might fail, or just mocking DB.
        DB::shouldReceive('transaction')->andThrow(new \Exception('Database failure'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database failure');

        $this->service->authenticateOrRegister($socialUser);
    }
}

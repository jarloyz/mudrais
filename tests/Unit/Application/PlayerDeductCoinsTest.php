<?php

namespace Tests\Unit\Application;

use App\Models\Player;
use App\Domains\Community\Models\PlayerTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerDeductCoinsTest extends TestCase
{
    use RefreshDatabase;

    public function test_deduct_coins_registers_transaction(): void
    {
        $player = Player::factory()->create(['coin' => 100]);

        $tx = $player->deductCoins(50, 'registro_edit', ['guild_id' => 'g1']);

        $player->refresh();
        $this->assertSame(50, $player->coin);
        $this->assertInstanceOf(PlayerTransaction::class, $tx);
        $this->assertSame('debit', $tx->type);
        $this->assertSame(50, $tx->amount);
        $this->assertSame(50, $tx->balance_after);
        $this->assertSame('registro_edit', $tx->description);
        $this->assertSame('g1', $tx->metadata['guild_id']);
    }

    public function test_deduct_coins_throws_if_insufficient_balance(): void
    {
        $player = Player::factory()->create(['coin' => 30]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Saldo insuficiente/');

        $player->deductCoins(50, 'registro_edit');
    }

    public function test_deduct_coins_does_not_persist_on_exception(): void
    {
        $player = Player::factory()->create(['coin' => 30]);

        try {
            $player->deductCoins(50, 'registro_edit');
        } catch (\RuntimeException) {
        }

        $player->refresh();
        $this->assertSame(30, $player->coin);
        $this->assertSame(0, $player->transactions()->count());
    }

    public function test_credit_coins_registers_transaction(): void
    {
        $player = Player::factory()->create(['coin' => 10]);

        $tx = $player->creditCoins(40, 'daily_reward');

        $player->refresh();
        $this->assertSame(50, $player->coin);
        $this->assertSame('credit', $tx->type);
        $this->assertSame(40, $tx->amount);
        $this->assertSame(50, $tx->balance_after);
    }
}

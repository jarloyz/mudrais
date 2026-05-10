<?php

namespace Tests\Unit\Application;

use App\Http\Controllers\Api\DiscordController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Verifica la validación del Modal Step 1 y el flujo de retry con caché.
 */
class RegistroStep1ValidationTest extends TestCase
{
    use RefreshDatabase;

    private function makeStep1Payload(string $discordId, array $fields): array
    {
        $components = [];
        foreach ($fields as $key => $value) {
            $components[] = [
                'type'       => 1,
                'components' => [['type' => 4, 'custom_id' => $key, 'value' => $value]],
            ];
        }

        return [
            'type'     => 5,
            'token'    => 'test-token',
            'guild_id' => 'guild-test',
            'data'     => ['custom_id' => 'mudrais_registro_step_1', 'components' => $components],
            'member'   => ['user' => ['id' => $discordId, 'username' => 'tester']],
        ];
    }

    private function validFields(): array
    {
        return [
            'nombre'       => 'Jarloyz',
            'edad'         => '28',
            'nacionalidad' => 'España',
            'genero'       => 'Hombre',
            'about_me'     => 'Me gusta el rol de terror.',
        ];
    }

    private function callHandle(array $payload): \Illuminate\Http\JsonResponse
    {
        $request = Request::create('/api/discord/interactions', 'POST', [], [], [], [], json_encode($payload));
        $request->headers->set('Content-Type', 'application/json');
        return app(DiscordController::class)->handle($request);
    }

    public function test_edad_invalida_devuelve_embed_error_y_guarda_cache_retry(): void
    {
        $discordId = 'user-test-validation';

        $response = $this->callHandle($this->makeStep1Payload($discordId, array_merge($this->validFields(), [
            'edad' => 'doce',
        ])));

        $json = $response->getData(true);

        // type:7 — muta el mensaje original (no crea mensaje nuevo, no tiene flags)
        $this->assertSame(7, $json['type']);
        $this->assertStringContainsString('edad', $json['data']['embeds'][0]['description']);
        $this->assertSame('btn_retry_modal_1', $json['data']['components'][0]['components'][0]['custom_id']);

        // Verifica que el cache de retry guardó el input
        $retry = Cache::get("registro_retry_{$discordId}");
        $this->assertSame('doce', $retry['edad']);
        $this->assertSame('España', $retry['nacionalidad']);
    }

    public function test_edad_fuera_de_rango_devuelve_error(): void
    {
        $response = $this->callHandle($this->makeStep1Payload('user-edad-rango-test', array_merge($this->validFields(), [
            'edad' => '150',
        ])));

        $json = $response->getData(true);
        $this->assertSame(7, $json['type']);
        $this->assertStringContainsString('edad', $json['data']['embeds'][0]['description']);
    }

    public function test_datos_validos_devuelve_boton_step2_y_guarda_cache(): void
    {
        $discordId = 'user-valid-test';
        Cache::put("registro_is_edit_{$discordId}", false, now()->addMinutes(30));

        $response = $this->callHandle($this->makeStep1Payload($discordId, $this->validFields()));

        $json = $response->getData(true);

        // type:7 — muta el embed de bienvenida al embed puente de Step 2
        $this->assertSame(7, $json['type']);
        $this->assertSame('btn_abrir_modal_2', $json['data']['components'][0]['components'][0]['custom_id']);

        // Verifica que se guardó el cache de step1 con is_edit=false
        $cached = Cache::get("registro_step1_{$discordId}");
        $this->assertSame('España', $cached['nacionalidad']);
        $this->assertFalse($cached['is_edit']);
    }
}

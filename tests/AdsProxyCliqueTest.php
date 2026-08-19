<?php

namespace HongaYetu\InternalAdsProxy\Tests;

use HongaYetu\InternalAdsProxy\AdsProxyServiceProvider;
use HongaYetu\InternalAdsProxy\Http\Controllers\AdsProxyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * O clique: quem é o cliente, e o rasto quando não há destino.
 *
 * Estes pedidos partem do **servidor** da app, não do aparelho de quem viu o
 * anúncio. Sem dizer de quem é o evento, a central vê sempre o mesmo IP e o
 * mesmo agente, e o guarda anti-fraude dela — que conta por IP e por minuto —
 * lê uma aplicação inteira como um aparelho a martelar.
 */
class AdsProxyCliqueTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AdsProxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ads-proxy.enabled', true);
        $app['config']->set('ads-proxy.base_url', 'https://anuncios.example/api/v2/ads');
        $app['config']->set('ads-proxy.token', 'chave-de-teste');
    }

    public function test_o_clique_declara_o_ip_e_o_agente_de_quem_viu(): void
    {
        Http::fake([
            '*/click' => Http::response([
                'estado' => 'ok',
                'data' => ['redirect_url' => 'https://anunciante.example/promo'],
            ]),
        ]);

        $pedido = Request::create('/api/ads-proxy/click/tok-123', 'GET', server: [
            'REMOTE_ADDR' => '41.222.10.7',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14)',
        ]);

        $resposta = $this->app->make(AdsProxyController::class)->clickRedirect($pedido, 'tok-123');

        $this->assertSame('https://anunciante.example/promo', $resposta->getTargetUrl());

        Http::assertSent(function ($request) {
            return $request['token'] === 'tok-123'
                && $request['client_ip'] === '41.222.10.7'
                && $request['client_user_agent'] === 'Mozilla/5.0 (Linux; Android 14)';
        });
    }

    /**
     * Sem destino cai-se no fallback — mas com um aviso no log.
     *
     * Enquanto isto foi silencioso, uma recusa sistemática da central levou
     * **todos** os cliques desta rede para a página inicial sem uma linha a
     * dizê-lo. O fallback é um desfecho legítimo; ser invisível não é.
     */
    public function test_sem_destino_cai_no_fallback(): void
    {
        config(['ads-proxy.click_fallback_url' => 'https://app.example/']);

        Http::fake([
            '*/click' => Http::response(['estado' => 'erro', 'texto' => 'Token expirado.'], 422),
        ]);

        $resposta = $this->app->make(AdsProxyController::class)
            ->clickRedirect(Request::create('/api/ads-proxy/click/tok-velho'), 'tok-velho');

        $this->assertSame('https://app.example/', $resposta->getTargetUrl());
    }
}

<?php

namespace HongaYetu\InternalAdsProxy\Tests;

use HongaYetu\InternalAdsProxy\AdsProxyServiceProvider;
use HongaYetu\InternalAdsProxy\Http\AdsProxyClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class AdsProxyClientTest extends TestCase
{
    public function test_marca_nao_configurado_quando_falta_token(): void
    {
        $cliente = new AdsProxyClient(
            baseUrl: 'https://anuncios.hongayetu.com/api/v2/ads',
            token: null,
        );

        $this->assertFalse($cliente->isConfigured());
    }

    public function test_marca_nao_configurado_quando_falta_base_url(): void
    {
        $cliente = new AdsProxyClient(
            baseUrl: '',
            token: 'fake',
        );

        $this->assertFalse($cliente->isConfigured());
    }

    public function test_marca_configurado_quando_ambos_presentes(): void
    {
        $cliente = new AdsProxyClient(
            baseUrl: 'https://anuncios.hongayetu.com/api/v2/ads',
            token: 'fake',
        );

        $this->assertTrue($cliente->isConfigured());
    }

    /**
     * Na mesma máquina da central, o pedido vai ao nginx local pelo IP. O nginx
     * só o entrega à central com o Host do site dela: sem ele, recusa (444).
     */
    public function test_com_host_header_o_pedido_vai_ao_endereco_com_o_host_da_central(): void
    {
        Http::fake(['*' => Http::response(['estado' => 'ok', 'data' => null])]);

        $cliente = new AdsProxyClient(
            baseUrl: 'http://127.0.0.1/api/v2/ads',
            token: 'fake',
            hostHeader: 'anuncios.hongayetu.com',
        );

        $cliente->serve(['espaco_slug' => 'humbi_shop']);
        $cliente->click(['token' => 'tok']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $pedido) => $pedido->url() === 'http://127.0.0.1/api/v2/ads/serve'
            && $pedido->header('Host') === ['anuncios.hongayetu.com']
            && $pedido->hasHeader('Authorization', 'Bearer fake'));
        Http::assertNotSent(fn (Request $pedido) => $pedido->header('Host') !== ['anuncios.hongayetu.com']);
    }

    public function test_sem_host_header_vai_o_host_do_endereco(): void
    {
        Http::fake(['*' => Http::response(['estado' => 'ok', 'data' => null])]);

        $cliente = new AdsProxyClient(
            baseUrl: 'http://127.0.0.1/api/v2/ads',
            token: 'fake',
            hostHeader: '  ',
        );

        $cliente->serve(['espaco_slug' => 'humbi_shop']);

        Http::assertSent(fn (Request $pedido) => $pedido->url() === 'http://127.0.0.1/api/v2/ads/serve'
            && $pedido->header('Host') === ['127.0.0.1']);
    }

    public function test_o_service_provider_le_o_host_header_do_config(): void
    {
        $this->app->register(AdsProxyServiceProvider::class);
        config([
            'ads-proxy.base_url' => 'http://127.0.0.1/api/v2/ads',
            'ads-proxy.token' => 'fake',
            'ads-proxy.host_header' => 'anuncios.hongayetu.com',
        ]);
        $this->app->forgetInstance(AdsProxyClient::class);

        Http::fake(['*' => Http::response(['estado' => 'ok', 'data' => null])]);

        $this->app->make(AdsProxyClient::class)->impression(['token' => 'tok']);

        Http::assertSent(fn (Request $pedido) => $pedido->url() === 'http://127.0.0.1/api/v2/ads/impression'
            && $pedido->header('Host') === ['anuncios.hongayetu.com']);
    }
}

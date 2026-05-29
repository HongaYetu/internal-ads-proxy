<?php

namespace HongaYetu\InternalAdsProxy\Tests;

use HongaYetu\InternalAdsProxy\Http\AdsProxyClient;
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
}

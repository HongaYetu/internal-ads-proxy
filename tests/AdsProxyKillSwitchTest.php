<?php

namespace HongaYetu\InternalAdsProxy\Tests;

use HongaYetu\InternalAdsProxy\AdsProxyServiceProvider;
use HongaYetu\InternalAdsProxy\Http\Controllers\AdsProxyController;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;

class AdsProxyKillSwitchTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AdsProxyServiceProvider::class];
    }

    public function test_serve_devolve_data_null_quando_desligado(): void
    {
        config(['ads-proxy.enabled' => false]);

        $controller = $this->app->make(AdsProxyController::class);
        $resposta = $controller->serve(new Request);

        $this->assertSame(200, $resposta->getStatusCode());
        $payload = $resposta->getData(true);
        $this->assertSame('ok', $payload['estado']);
        $this->assertNull($payload['data']);
        $this->assertSame('ads_disabled', $payload['meta']['reason']);
    }

    public function test_impression_devolve_data_null_quando_desligado(): void
    {
        config(['ads-proxy.enabled' => false]);

        $controller = $this->app->make(AdsProxyController::class);
        $resposta = $controller->impression(new Request);

        $this->assertSame(200, $resposta->getStatusCode());
        $this->assertNull($resposta->getData(true)['data']);
    }

    public function test_click_devolve_data_null_quando_desligado(): void
    {
        config(['ads-proxy.enabled' => false]);

        $controller = $this->app->make(AdsProxyController::class);
        $resposta = $controller->click(new Request);

        $this->assertSame(200, $resposta->getStatusCode());
        $this->assertNull($resposta->getData(true)['data']);
    }
}

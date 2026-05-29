<?php

namespace HongaYetu\InternalAdsProxy;

use HongaYetu\InternalAdsProxy\Http\AdsProxyClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AdsProxyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ads-proxy.php', 'ads-proxy');

        $this->app->singleton(AdsProxyClient::class, function (Application $app) {
            $config = $app['config']->get('ads-proxy');

            return new AdsProxyClient(
                baseUrl: (string) ($config['base_url'] ?? ''),
                token: $config['token'] ?? null,
                timeoutSeconds: (int) ($config['timeout'] ?? 8),
                logChannel: $config['log_channel'] ?? null,
            );
        });
    }

    public function boot(): void
    {
        // Permite ao consumer publicar o config para customizar.
        $this->publishes([
            __DIR__.'/../config/ads-proxy.php' => $this->app->configPath('ads-proxy.php'),
        ], 'hongayetu-ads-proxy-config');

        if ((bool) $this->app['config']->get('ads-proxy.auto_register_routes', true)) {
            $this->registarRotas();
        }
    }

    protected function registarRotas(): void
    {
        $config = $this->app['config']->get('ads-proxy');

        Route::middleware($config['middleware'] ?? [])
            ->prefix($config['route_prefix'] ?? 'api/ads-proxy')
            ->name($config['route_name_prefix'] ?? 'ads-proxy.')
            ->group(__DIR__.'/../routes/proxy.php');
    }
}

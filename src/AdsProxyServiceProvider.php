<?php

namespace HongaYetu\InternalAdsProxy;

use HongaYetu\InternalAdsProxy\Http\AdsProxyClient;
use HongaYetu\InternalAdsProxy\Http\MensagensPopupClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AdsProxyServiceProvider extends ServiceProvider
{
    /*
     * Os valores por omissão das mensagens estão também aqui, e não só no
     * config: o `mergeConfigFrom` não junta chaves aninhadas, e um serviço que
     * publicou o `config/ads-proxy.php` antes da 0.10.0 não tem a chave
     * `mensagens`. Sem isto, as rotas ficavam sem `auth` nesses serviços.
     */
    public const MENSAGENS_BASE_URL = 'https://hongayetu.com/api/v1/servicos/mensagens-popup';

    public const MENSAGENS_MIDDLEWARE = ['auth:sanctum', 'throttle:60,1,mensagens-popup'];

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

        $this->app->singleton(MensagensPopupClient::class, function (Application $app) {
            $config = $app['config']->get('ads-proxy');
            $mensagens = (array) ($config['mensagens'] ?? []);

            return new MensagensPopupClient(
                baseUrl: (string) ($mensagens['base_url'] ?? self::MENSAGENS_BASE_URL),
                // Sem token próprio, as mensagens usam o dos anúncios: é o mesmo ConnectedProject.
                token: ($mensagens['token'] ?? null) ?: ($config['token'] ?? null),
                timeoutSeconds: (int) ($mensagens['timeout'] ?? 5),
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

        if ((bool) $this->app['config']->get('ads-proxy.mensagens.auto_register_routes', true)) {
            $this->registarRotasDasMensagens();
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

    protected function registarRotasDasMensagens(): void
    {
        $config = (array) $this->app['config']->get('ads-proxy.mensagens', []);

        Route::middleware($config['middleware'] ?? self::MENSAGENS_MIDDLEWARE)
            ->prefix($config['route_prefix'] ?? 'api/mensagens-popup')
            ->name($config['route_name_prefix'] ?? 'mensagens-popup.')
            ->group(__DIR__.'/../routes/mensagens.php');
    }
}

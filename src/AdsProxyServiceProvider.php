<?php

namespace HongaYetu\InternalAdsProxy;

use HongaYetu\InternalAdsProxy\Http\AdsProxyClient;
use HongaYetu\InternalAdsProxy\Http\Controllers\MensagensPopupController;
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

        if ((bool) $this->app['config']->get('ads-proxy.mensagens.web.auto_register_routes', true)) {
            $this->registarRotasDasMensagensNosSites();
        }

        $this->registarDirectiva();
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

    /**
     * As mensagens nos sites: a lista e os eventos com a sessão web, e o
     * `popup.js` à parte, sem sessão nem `auth` (é público e fica em cache).
     */
    protected function registarRotasDasMensagensNosSites(): void
    {
        $config = (array) $this->app['config']->get('ads-proxy.mensagens.web', []);
        $prefixo = $config['route_prefix'] ?? MensagensPopupWeb::PREFIXO;
        $nomes = $config['route_name_prefix'] ?? MensagensPopupWeb::PREFIXO_DOS_NOMES;

        Route::middleware($config['middleware'] ?? MensagensPopupWeb::MIDDLEWARE)
            ->prefix($prefixo)
            ->name($nomes)
            ->group(__DIR__.'/../routes/mensagens-web.php');

        Route::middleware($config['script_middleware'] ?? MensagensPopupWeb::MIDDLEWARE_DO_SCRIPT)
            ->prefix($prefixo)
            ->name($nomes)
            ->group(function (): void {
                Route::get('/janela', [MensagensPopupController::class, 'script'])->name('script');
            });
    }

    /** `@mensagensPopupHonga` na página de raiz do site: escreve a etiqueta do `popup.js`, ou nada. */
    protected function registarDirectiva(): void
    {
        $this->callAfterResolving('blade.compiler', function ($blade): void {
            $blade->directive(
                'mensagensPopupHonga',
                fn (): string => '<?php echo \\'.MensagensPopupWeb::class.'::etiqueta(); ?>',
            );
        });
    }
}

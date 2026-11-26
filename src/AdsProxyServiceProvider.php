<?php

namespace HongaYetu\InternalAdsProxy;

use HongaYetu\InternalAdsProxy\Http\AdsProxyClient;
use HongaYetu\InternalAdsProxy\Http\Controllers\FeedbackController;
use HongaYetu\InternalAdsProxy\Http\Controllers\InteressesController;
use HongaYetu\InternalAdsProxy\Http\Controllers\MensagensPopupController;
use HongaYetu\InternalAdsProxy\Http\FeedbackClient;
use HongaYetu\InternalAdsProxy\Http\InteressesClient;
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

    public const FEEDBACK_BASE_URL = 'https://hongayetu.com/api/v1/servicos/feedback';

    public const FEEDBACK_MIDDLEWARE = ['throttle:10,1,feedback'];

    public const FEEDBACK_CAPTURAS_MIDDLEWARE = ['throttle:12,1,feedback-capturas'];

    public const FEEDBACK_WEB_MIDDLEWARE = ['web', 'throttle:10,1,feedback-web'];

    public const FEEDBACK_WEB_CAPTURAS_MIDDLEWARE = ['web', 'throttle:12,1,feedback-capturas-web'];

    public const WEB_MIDDLEWARE = ['web', 'throttle:120,1,anuncios-web'];

    public const INTERESSES_BASE_URL = 'https://hongayetu.com/api/v1/servicos/interesses';

    public const INTERESSES_LIMITE = 'throttle:120,1,interesses';

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

        $this->app->singleton(FeedbackClient::class, function (Application $app) {
            $config = $app['config']->get('ads-proxy');
            $feedback = (array) ($config['feedback'] ?? []);
            $mensagens = (array) ($config['mensagens'] ?? []);

            return new FeedbackClient(
                baseUrl: (string) ($feedback['base_url'] ?? self::FEEDBACK_BASE_URL),
                // O mesmo ConnectedProject: sem token próprio, o das mensagens e, por fim, o dos anúncios.
                token: ($feedback['token'] ?? null) ?: ($mensagens['token'] ?? null) ?: ($config['token'] ?? null),
                timeoutSeconds: (int) ($feedback['timeout'] ?? 8),
            );
        });

        $this->app->singleton(InteressesClient::class, function (Application $app) {
            $config = $app['config']->get('ads-proxy');
            $interesses = (array) ($config['interesses'] ?? []);

            return new InteressesClient(
                baseUrl: (string) ($interesses['base_url'] ?? self::INTERESSES_BASE_URL),
                // O mesmo ConnectedProject: sem token próprio, o dos anúncios.
                token: ($interesses['token'] ?? null) ?: ($config['token'] ?? null),
                timeoutSeconds: max(1, (int) ($interesses['timeout'] ?? 3)),
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

        if ((bool) $this->app['config']->get('ads-proxy.web.auto_register_routes', false)) {
            $this->registarRotasNosSites();
        }

        if ((bool) $this->app['config']->get('ads-proxy.interesses.auto_register_routes', true)) {
            $this->registarRotaDosInteresses();
        }

        if ((bool) $this->app['config']->get('ads-proxy.mensagens.auto_register_routes', true)) {
            $this->registarRotasDasMensagens();
        }

        if ((bool) $this->app['config']->get('ads-proxy.mensagens.web.auto_register_routes', true)) {
            $this->registarRotasDasMensagensNosSites();
        }

        if ((bool) $this->app['config']->get('ads-proxy.feedback.auto_register_routes', true)) {
            $this->registarRotasDoFeedback();
        }

        if ((bool) $this->app['config']->get('ads-proxy.feedback.web.auto_register_routes', true)) {
            $this->registarRotasDoFeedbackNosSites();
        }

        $this->registarDirectiva();
    }

    /**
     * Os anúncios nos sites: `serve`, `impression`, `click`, `video-event` e `interesses` num prefixo fora
     * de `api/` (por omissão `anuncios-proxy`), com o grupo `web`. Um browser só tem sessão com o `web`, e
     * sem sessão o proxy não sabe quem está a ver. As rotas `api/ads-proxy/*` ficam para as apps (Bearer).
     * Desligadas por omissão: `ads-proxy.web.auto_register_routes`.
     */
    protected function registarRotasNosSites(): void
    {
        $config = (array) $this->app['config']->get('ads-proxy.web', []);
        $middleware = $config['middleware'] ?? null;

        Route::middleware(is_array($middleware) ? $middleware : self::WEB_MIDDLEWARE)
            ->prefix($config['route_prefix'] ?? 'anuncios-proxy')
            ->name($config['route_name_prefix'] ?? 'ads-proxy.web.')
            ->group(__DIR__.'/../routes/proxy-web.php');
    }

    /**
     * Os interesses: `POST {prefixo do proxy}/interesses`, ao lado do `serve`.
     *
     * Sem `auth`: um visitante recebe `200 {"estado":"ok","registados":0}`, e não um 401 que a app teria de
     * tratar. Por omissão leva o middleware dos anúncios menos os `auth…` e os `throttle…` (o que sobra é o
     * que dá a sessão ao pedido, como o `web`), mais um limite com nome próprio.
     */
    protected function registarRotaDosInteresses(): void
    {
        $config = (array) $this->app['config']->get('ads-proxy', []);
        $interesses = (array) ($config['interesses'] ?? []);

        $middleware = $interesses['middleware'] ?? null;

        if (! is_array($middleware)) {
            $middleware = array_values(array_filter(
                (array) ($config['middleware'] ?? []),
                fn ($m) => is_string($m) && ! str_starts_with($m, 'auth') && ! str_starts_with($m, 'throttle'),
            ));
            $middleware[] = self::INTERESSES_LIMITE;
        }

        Route::prefix($config['route_prefix'] ?? 'api/ads-proxy')
            ->name($config['route_name_prefix'] ?? 'ads-proxy.')
            ->middleware($middleware)
            ->group(function () {
                Route::post('/interesses', [InteressesController::class, 'registar'])->name('interesses');
            });
    }

    /**
     * O feedback na API do serviço: `POST {prefixo}` e `POST {prefixo}/capturas`. Sem `auth` por omissão,
     * porque o feedback aceita visitantes. As capturas têm o middleware (e o limite) delas.
     */
    protected function registarRotasDoFeedback(): void
    {
        $config = (array) $this->app['config']->get('ads-proxy.feedback', []);
        $prefixo = $config['route_prefix'] ?? 'api/feedback';
        $nomes = $config['route_name_prefix'] ?? 'feedback.';

        Route::middleware($config['middleware'] ?? self::FEEDBACK_MIDDLEWARE)
            ->prefix($prefixo)->name($nomes)
            ->group(function (): void {
                Route::post('/', [FeedbackController::class, 'enviar'])->name('enviar');
            });

        Route::middleware($config['capturas_middleware'] ?? self::FEEDBACK_CAPTURAS_MIDDLEWARE)
            ->prefix($prefixo)->name($nomes)
            ->group(function (): void {
                Route::post('/capturas', [FeedbackController::class, 'capturar'])->name('capturas');
            });
    }

    /** O feedback nos sites (sessão web, com CSRF): `POST feedback` e `POST feedback/capturas`. */
    protected function registarRotasDoFeedbackNosSites(): void
    {
        $config = (array) $this->app['config']->get('ads-proxy.feedback.web', []);
        $prefixo = $config['route_prefix'] ?? 'feedback';
        $nomes = $config['route_name_prefix'] ?? 'feedback.web.';

        Route::middleware($config['middleware'] ?? self::FEEDBACK_WEB_MIDDLEWARE)
            ->prefix($prefixo)->name($nomes)
            ->group(function (): void {
                Route::post('/', [FeedbackController::class, 'enviarWeb'])->name('enviar');
            });

        Route::middleware($config['capturas_middleware'] ?? self::FEEDBACK_WEB_CAPTURAS_MIDDLEWARE)
            ->prefix($prefixo)->name($nomes)
            ->group(function (): void {
                Route::post('/capturas', [FeedbackController::class, 'capturar'])->name('capturas');
            });
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

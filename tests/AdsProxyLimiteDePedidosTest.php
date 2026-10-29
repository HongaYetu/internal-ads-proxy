<?php

namespace HongaYetu\InternalAdsProxy\Tests;

use HongaYetu\InternalAdsProxy\AdsProxyServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * O limite das rotas do proxy tem contador próprio.
 *
 * Um `throttle:N,M` sem o terceiro argumento usa a mesma chave — o utilizador,
 * ou o domínio e o IP — em **todas** as rotas, seja qual for o N. Os pedidos
 * dos anúncios de um ecrã (serve, impressões, cliques, vídeo) gastavam assim
 * o limite de outras rotas da app: no DesAppego esgotavam o limite do login,
 * e a revisão do Google recusou a app a 28/10/2026.
 *
 * Estes testes correm com o `middleware` por omissão do package, que é o que
 * fica num serviço que o instale sem `config/ads-proxy.php` próprio.
 */
class AdsProxyLimiteDePedidosTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AdsProxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ads-proxy.base_url', 'https://anuncios.example/api/v2/ads');
        $app['config']->set('ads-proxy.token', 'chave-de-teste');

        // O `auth:sanctum` do middleware por omissão, sem o Sanctum: um guarda
        // que reconhece sempre o mesmo utilizador.
        $app['config']->set('auth.guards.sanctum', ['driver' => 'utilizador-de-teste']);
        $app['auth']->viaRequest('utilizador-de-teste', fn () => new GenericUser(['id' => 7]));
    }

    protected function defineRoutes($router): void
    {
        // Uma rota da app com um limite baixo e sem nome, como estava o login do DesAppego.
        $router->post('/mensagens', fn () => response()->json(['estado' => 'ok']))
            ->middleware(['auth:sanctum', 'throttle:10,1']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*' => Http::response(['estado' => 'ok', 'data' => null])]);
    }

    public function test_o_limite_por_omissao_tem_nome(): void
    {
        $limites = array_values(array_filter(
            config('ads-proxy.middleware'),
            fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'),
        ));

        $this->assertSame(['throttle:60,1,anuncios'], $limites);
    }

    public function test_os_pedidos_aos_anuncios_nao_gastam_o_limite_de_outra_rota(): void
    {
        foreach (range(1, 60) as $ignorado) {
            $this->postJson('/api/ads-proxy/impression', [])->assertStatus(422);
        }

        $this->postJson('/mensagens')->assertOk();
    }

    public function test_o_proxy_continua_limitado_a_60_pedidos_por_minuto(): void
    {
        foreach (range(1, 60) as $ignorado) {
            $this->postJson('/api/ads-proxy/impression', [])->assertStatus(422);
        }

        $this->postJson('/api/ads-proxy/impression', [])->assertStatus(429);
    }
}

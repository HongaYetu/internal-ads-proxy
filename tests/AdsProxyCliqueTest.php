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
        // Para as rotas: sem `auth:sanctum` (não há Sanctum no Testbench) e com as rotas dos sites.
        $app['config']->set('ads-proxy.middleware', []);
        $app['config']->set('ads-proxy.web.auto_register_routes', true);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
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

    /**
     * Um robô que segue a ligação do anúncio leva 404 e a central nem chega a
     * ser chamada: ela já não lhe contava o clique, e cada um custava-lhe um
     * pedido (o Reflectionbot, 54 mil em 15 dias).
     *
     * @return iterable<string, array{string}>
     */
    public static function agentesDeRobos(): iterable
    {
        yield 'Reflectionbot' => ['Mozilla/5.0 (compatible; Reflectionbot/1.0; +https://reflection.example/bot)'];
        yield 'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
        yield 'Chrome sem cabeça' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0 Safari/537.36'];
        yield 'curl' => ['curl/8.5.0'];
        yield 'python-requests' => ['python-requests/2.32.3'];
        yield 'maiúsculas' => ['SOMESPIDER/1.0'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('agentesDeRobos')]
    public function test_um_robo_leva_404_sem_chamar_a_central(string $agente): void
    {
        Http::fake();

        $pedido = Request::create('/api/ads-proxy/click/tok-123', 'GET', server: ['HTTP_USER_AGENT' => $agente]);

        $resposta = $this->app->make(AdsProxyController::class)->clickRedirect($pedido, 'tok-123');

        $this->assertSame(404, $resposta->getStatusCode());
        $this->assertSame('noindex, nofollow', $resposta->headers->get('X-Robots-Tag'));
        Http::assertNothingSent();
    }

    /** Pela rota, nos dois prefixos (o da API e o dos sites). */
    public function test_a_rota_do_clique_responde_404_a_um_robo_nos_dois_prefixos(): void
    {
        Http::fake();

        $this->get('/api/ads-proxy/click/tok-123', ['User-Agent' => 'Reflectionbot/1.0'])->assertNotFound();
        $this->get('/anuncios-proxy/click/tok-123', ['User-Agent' => 'Reflectionbot/1.0'])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_um_browser_segue_para_o_anunciante(): void
    {
        Http::fake([
            '*/click' => Http::response(['estado' => 'ok', 'data' => ['redirect_url' => 'https://anunciante.example/promo']]),
        ]);

        $pedido = Request::create('/api/ads-proxy/click/tok-123', 'GET', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
        ]);

        $resposta = $this->app->make(AdsProxyController::class)->clickRedirect($pedido, 'tok-123');

        $this->assertSame(302, $resposta->getStatusCode());
        Http::assertSentCount(1);
    }

    /** Um agente vazio não se julga robô: segue como até aqui. */
    public function test_sem_agente_nao_e_robo(): void
    {
        Http::fake([
            '*/click' => Http::response(['estado' => 'ok', 'data' => ['redirect_url' => 'https://anunciante.example/promo']]),
        ]);

        $pedido = Request::create('/api/ads-proxy/click/tok-123', 'GET', server: ['HTTP_USER_AGENT' => '']);

        $resposta = $this->app->make(AdsProxyController::class)->clickRedirect($pedido, 'tok-123');

        $this->assertSame('https://anunciante.example/promo', $resposta->getTargetUrl());
    }

    /** Um `config/ads-proxy.php` publicado com outra lista manda nela. */
    public function test_a_lista_de_robos_vem_do_config(): void
    {
        config(['ads-proxy.robos' => ['meurobo']]);
        Http::fake([
            '*/click' => Http::response(['estado' => 'ok', 'data' => ['redirect_url' => 'https://anunciante.example/promo']]),
        ]);

        $robo = Request::create('/api/ads-proxy/click/a', 'GET', server: ['HTTP_USER_AGENT' => 'MeuRobo/2.0']);
        $outro = Request::create('/api/ads-proxy/click/b', 'GET', server: ['HTTP_USER_AGENT' => 'Googlebot/2.1']);

        $controlador = $this->app->make(AdsProxyController::class);

        $this->assertSame(404, $controlador->clickRedirect($robo, 'a')->getStatusCode());
        $this->assertSame(302, $controlador->clickRedirect($outro, 'b')->getStatusCode());
    }
}

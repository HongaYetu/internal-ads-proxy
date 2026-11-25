<?php

namespace HongaYetu\InternalAdsProxy\Tests;

use HongaYetu\InternalAdsProxy\AdsProxyServiceProvider;
use HongaYetu\InternalAdsProxy\MensagensPopupWeb;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

/**
 * As mensagens em popup nos sites: as rotas com a sessão web, o `popup.js` e a
 * directiva `@mensagensPopupHonga`.
 *
 * O que estes testes guardam, além do que o `MensagensPopupTest` já guarda: na
 * web o `servico` e a `superficie` só valem se forem os de uma zona
 * configurada, e a directiva só escreve a etiqueta para quem tem sessão.
 */
class MensagensPopupWebTest extends TestCase
{
    protected const CENTRAL = 'https://central.example/api/v1/servicos/mensagens-popup';

    protected function getPackageProviders($app): array
    {
        return [AdsProxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // O grupo `web` cifra os cookies.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('ads-proxy.token', 'chave-de-teste');
        $app['config']->set('ads-proxy.mensagens.base_url', self::CENTRAL);
        $app['config']->set('ads-proxy.mensagens.web.zonas', [
            ['servico' => 'humbi', 'superficie' => 'site', 'caminhos' => ['/', '/inicio/'], 'anfitrioes' => []],
            ['servico' => 'humbi-negocio', 'superficie' => 'painel', 'caminhos' => ['/painel'], 'anfitrioes' => ['Negocio.Humbi.Test']],
        ]);
    }

    protected function comSessao(array $atributos = ['id' => 7, 'honga_user_id' => 321]): static
    {
        return $this->actingAs(new GenericUser($atributos));
    }

    protected function centralResponde(array $corpo = ['estado' => 'ok'], int $estado = 200): void
    {
        Http::fake(['*' => Http::response($corpo, $estado)]);
    }

    public function test_as_rotas_web_ficam_registadas_com_sessao_e_contador_proprio(): void
    {
        $lista = Route::getRoutes()->getByName('mensagens-popup.web.index');
        $eventos = Route::getRoutes()->getByName('mensagens-popup.web.eventos');
        $script = Route::getRoutes()->getByName('mensagens-popup.web.script');

        $this->assertSame('mensagens-popup', $lista->uri());
        $this->assertSame('mensagens-popup/{mensagem}/eventos', $eventos->uri());
        $this->assertSame('mensagens-popup/popup.js', $script->uri());

        foreach ([$lista, $eventos] as $rota) {
            $this->assertSame(['web', 'auth', 'throttle:60,1,mensagens-popup-web'], $rota->gatherMiddleware());
        }

        // O script é público: sem sessão nem `auth`, só o limite (com nome próprio).
        $this->assertSame(['throttle:120,1,mensagens-popup-script'], $script->gatherMiddleware());
    }

    public function test_sem_sessao_a_lista_e_os_eventos_respondem_401(): void
    {
        Http::fake();

        $this->getJson('/mensagens-popup?servico=humbi&superficie=site')->assertUnauthorized();
        $this->postJson('/mensagens-popup/9/eventos', ['tipo' => 'vista', 'servico' => 'humbi', 'superficie' => 'site'])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_com_sessao_a_lista_vai_a_central_em_nome_da_conta(): void
    {
        $this->centralResponde(['estado' => 'ok', 'mensagens' => [['id' => 9, 'tipo' => 'novidade']]]);

        $this->comSessao()
            ->getJson('/mensagens-popup?servico=humbi&superficie=site')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => [['id' => 9, 'tipo' => 'novidade']]]);

        Http::assertSentCount(1);
        Http::assertSent(function ($pedido) {
            parse_str((string) parse_url($pedido->url(), PHP_URL_QUERY), $query);

            return $pedido->method() === 'GET'
                && str_starts_with($pedido->url(), self::CENTRAL.'?')
                && $pedido->hasHeader('Authorization', 'Bearer chave-de-teste')
                && $query === ['honga_user_id' => '321', 'servico' => 'humbi', 'superficie' => 'site'];
        });
    }

    public function test_o_evento_vai_a_central_com_a_zona_e_a_conta_da_sessao(): void
    {
        $this->centralResponde();

        $this->comSessao()
            ->postJson('/mensagens-popup/9/eventos', [
                'tipo' => 'clique',
                'servico' => 'humbi',
                'superficie' => 'site',
                'botao' => 'ligacao_texto',
                'destino' => 'https://humbi.example/promo',
            ])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok']);

        Http::assertSentCount(1);
        Http::assertSent(function ($pedido) {
            $corpo = $pedido->data();
            ksort($corpo);

            return $pedido->method() === 'POST'
                && $pedido->url() === self::CENTRAL.'/9/eventos'
                && $corpo === [
                    'botao' => 'ligacao_texto',
                    'destino' => 'https://humbi.example/promo',
                    'honga_user_id' => 321,
                    'servico' => 'humbi',
                    'superficie' => 'site',
                    'tipo' => 'clique',
                ];
        });
    }

    public function test_o_honga_user_id_do_pedido_e_ignorado(): void
    {
        $this->centralResponde(['estado' => 'ok', 'mensagens' => []]);

        $this->comSessao()->getJson('/mensagens-popup?servico=humbi&superficie=site&honga_user_id=999')->assertOk();
        $this->comSessao()->postJson('/mensagens-popup/9/eventos', [
            'tipo' => 'vista', 'servico' => 'humbi', 'superficie' => 'site', 'honga_user_id' => 999,
        ])->assertOk();

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($pedido) => str_contains($pedido->url().$pedido->body(), '999'));
        Http::assertSent(fn ($pedido) => $pedido->method() === 'GET' && str_contains($pedido->url(), 'honga_user_id=321'));
        Http::assertSent(fn ($pedido) => $pedido->method() === 'POST' && $pedido['honga_user_id'] === 321);
    }

    public function test_uma_zona_que_nao_existe_recebe_a_lista_vazia_sem_chamar_a_central(): void
    {
        Http::fake();

        foreach ([
            'servico=kanda&superficie=site',      // serviço sem zona
            'servico=humbi&superficie=painel',    // a zona do humbi é o site
            'servico=humbi',                      // sem superfície
            'superficie=site',                    // sem serviço
            '',
        ] as $query) {
            $this->comSessao()
                ->getJson('/mensagens-popup?'.$query)
                ->assertOk()
                ->assertExactJson(['estado' => 'ok', 'mensagens' => []]);
        }

        $this->comSessao()
            ->postJson('/mensagens-popup/9/eventos', ['tipo' => 'vista', 'servico' => 'kanda', 'superficie' => 'site'])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok']);

        $this->comSessao()
            ->postJson('/mensagens-popup/9/eventos', ['tipo' => 'vista'])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok']);

        Http::assertNothingSent();
    }

    public function test_a_superficie_app_nao_se_aceita_pelas_rotas_web(): void
    {
        // Mesmo que alguém a escreva numa zona, a `app` é só das rotas da API.
        config(['ads-proxy.mensagens.web.zonas' => [
            ['servico' => 'humbi', 'superficie' => 'app', 'caminhos' => ['/']],
            ['servico' => 'humbi', 'superficie' => 'site', 'caminhos' => ['/']],
        ]]);
        Http::fake();

        $this->comSessao()
            ->getJson('/mensagens-popup?servico=humbi&superficie=app')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => []]);

        $this->comSessao()
            ->postJson('/mensagens-popup/9/eventos', ['tipo' => 'vista', 'servico' => 'humbi', 'superficie' => 'app'])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok']);

        Http::assertNothingSent();
        $this->assertSame(['site'], array_column(MensagensPopupWeb::zonas(), 'superficie'));
    }

    public function test_uma_zona_com_dominios_so_vale_nesses_dominios(): void
    {
        $this->centralResponde(['estado' => 'ok', 'mensagens' => [['id' => 9]]]);

        $this->comSessao()
            ->getJson('http://humbi.test/mensagens-popup?servico=humbi-negocio&superficie=painel')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => []]);

        Http::assertNothingSent();

        $this->comSessao()
            ->getJson('http://negocio.humbi.test/mensagens-popup?servico=humbi-negocio&superficie=painel')
            ->assertOk()
            ->assertJsonPath('mensagens.0.id', 9);

        Http::assertSentCount(1);
    }

    public function test_sem_conta_honga_yetu_nada_vai_a_central(): void
    {
        Http::fake();

        $this->comSessao(['id' => 7, 'honga_user_id' => null])
            ->getJson('/mensagens-popup?servico=humbi&superficie=site')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => []]);

        Http::assertNothingSent();
    }

    public function test_desligado_na_web_responde_vazio_sem_chamar_a_central(): void
    {
        config(['ads-proxy.mensagens.web.enabled' => false]);
        Http::fake();

        $this->comSessao()
            ->getJson('/mensagens-popup?servico=humbi&superficie=site')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => []]);

        $this->comSessao()
            ->postJson('/mensagens-popup/9/eventos', ['tipo' => 'vista', 'servico' => 'humbi', 'superficie' => 'site'])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok']);

        Http::assertNothingSent();
        $this->assertSame('', Blade::render('@mensagensPopupHonga'));
    }

    public function test_a_central_em_baixo_nao_parte_o_site(): void
    {
        $this->centralResponde(['message' => 'Server Error'], 500);

        $this->comSessao()
            ->getJson('/mensagens-popup?servico=humbi&superficie=site')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => []]);
    }

    public function test_a_mensagem_do_evento_tem_de_ser_numerica(): void
    {
        Http::fake();

        $this->comSessao()
            ->postJson('/mensagens-popup/abc/eventos', ['tipo' => 'vista', 'servico' => 'humbi', 'superficie' => 'site'])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_o_popup_js_e_servido_sem_sessao_com_o_tipo_e_a_cache_certos(): void
    {
        $resposta = $this->get('/mensagens-popup/popup.js?v='.MensagensPopupWeb::versaoDoScript());

        $resposta->assertOk();
        $this->assertSame('application/javascript; charset=utf-8', $resposta->headers->get('Content-Type'));
        $this->assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'));

        $cache = (string) $resposta->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=3600', $cache);
        $this->assertStringNotContainsString('private', $cache);
        $this->assertStringNotContainsString('no-cache', $cache);

        // Sem sessão: nada de cookies numa resposta que fica em cache.
        $this->assertSame([], $resposta->headers->getCookies());

        $this->assertSame(file_get_contents(MensagensPopupWeb::caminhoDoScript()), $resposta->getContent());
        $this->assertStringContainsString('data-zonas', $resposta->getContent());
    }

    public function test_o_popup_js_responde_304_a_quem_ja_tem_a_copia(): void
    {
        $etag = $this->get('/mensagens-popup/popup.js')->headers->get('ETag');

        $this->assertNotEmpty($etag);
        $this->get('/mensagens-popup/popup.js', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_a_directiva_escreve_a_etiqueta_com_sessao_e_zonas(): void
    {
        $this->startSession();
        $this->comSessao(['id' => 7, 'honga_user_id' => 321, 'name' => 'Ana Kiala', 'email' => 'ana@example.com']);

        $html = Blade::render('<body>@mensagensPopupHonga</body>');

        $this->assertSame(1, substr_count($html, '<script'));
        $this->assertStringContainsString('<script defer src="/mensagens-popup/popup.js?v='.MensagensPopupWeb::versaoDoScript().'"', $html);
        $this->assertStringContainsString(' data-raiz="/mensagens-popup"', $html);
        $this->assertStringContainsString(' data-csrf="'.csrf_token().'"', $html);
        $this->assertNotEmpty(csrf_token());

        $this->assertSame(1, preg_match("/ data-zonas='([^']*)'/", $html, $achado));
        $this->assertSame([
            ['servico' => 'humbi', 'superficie' => 'site', 'caminhos' => ['/', '/inicio'], 'anfitrioes' => []],
            ['servico' => 'humbi-negocio', 'superficie' => 'painel', 'caminhos' => ['/painel'], 'anfitrioes' => ['negocio.humbi.test']],
        ], json_decode(html_entity_decode($achado[1]), true));

        // Nada da pessoa no HTML.
        foreach (['321', 'Ana', 'ana@example.com', 'honga_user_id'] as $pessoal) {
            $this->assertStringNotContainsString($pessoal, $html);
        }
    }

    public function test_a_directiva_nao_escreve_nada_sem_sessao(): void
    {
        $this->assertSame('<body></body>', Blade::render('<body>@mensagensPopupHonga</body>'));
    }

    public function test_a_directiva_nao_escreve_nada_sem_conta_honga_yetu(): void
    {
        $this->comSessao(['id' => 7, 'honga_user_id' => null]);

        $this->assertSame('', Blade::render('@mensagensPopupHonga'));
    }

    public function test_a_directiva_nao_escreve_nada_sem_zonas(): void
    {
        config(['ads-proxy.mensagens.web.zonas' => []]);
        $this->comSessao();

        $this->assertSame('', Blade::render('@mensagensPopupHonga'));
    }

    public function test_a_directiva_nao_escreve_nada_com_as_mensagens_desligadas(): void
    {
        config(['ads-proxy.mensagens.enabled' => false]);
        $this->comSessao();

        $this->assertSame('', Blade::render('@mensagensPopupHonga'));
    }

    public function test_a_directiva_procura_a_sessao_no_guard_configurado(): void
    {
        config([
            'auth.guards.cliente' => ['driver' => 'session', 'provider' => 'users'],
            'ads-proxy.mensagens.web.guard' => 'cliente',
        ]);

        // Autenticado no guard por omissão, mas não no do site.
        $this->comSessao();
        $this->assertSame('', Blade::render('@mensagensPopupHonga'));

        $this->actingAs(new GenericUser(['id' => 7, 'honga_user_id' => 321]), 'cliente');
        $this->assertStringContainsString('popup.js', Blade::render('@mensagensPopupHonga'));
    }

    public function test_o_json_das_zonas_nao_consegue_sair_do_atributo(): void
    {
        config(['ads-proxy.mensagens.web.zonas' => [
            ['servico' => "hu'mbi\"><script>alert(1)</script>&", 'superficie' => 'site', 'caminhos' => ['/']],
        ]]);
        $this->comSessao();

        $html = Blade::render('@mensagensPopupHonga');

        $this->assertSame(1, substr_count($html, '<script'));
        $this->assertSame(1, substr_count($html, '</script>'));
        $this->assertSame(1, preg_match("/ data-zonas='([^']*)'/", $html, $achado));
        $this->assertSame("hu'mbi\"><script>alert(1)</script>&", json_decode($achado[1], true)[0]['servico']);
    }

    public function test_sem_o_bloco_web_no_config_publicado_valem_os_valores_por_omissao(): void
    {
        $this->assertSame(['web', 'auth', 'throttle:60,1,mensagens-popup-web'], MensagensPopupWeb::MIDDLEWARE);
        $this->assertSame(MensagensPopupWeb::MIDDLEWARE, config('ads-proxy.mensagens.web.middleware'));
        $this->assertSame(MensagensPopupWeb::MIDDLEWARE_DO_SCRIPT, config('ads-proxy.mensagens.web.script_middleware'));
        $this->assertSame(MensagensPopupWeb::PREFIXO, config('ads-proxy.mensagens.web.route_prefix'));
        $this->assertSame(MensagensPopupWeb::PREFIXO_DOS_NOMES, config('ads-proxy.mensagens.web.route_name_prefix'));

        config(['ads-proxy.mensagens.web' => null]);

        $this->assertTrue(MensagensPopupWeb::ligada());
        $this->assertSame([], MensagensPopupWeb::zonas());
        $this->assertSame('', Blade::render('@mensagensPopupHonga'));
    }
}

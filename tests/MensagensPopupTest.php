<?php

namespace HongaYetu\InternalAdsProxy\Tests;

use HongaYetu\InternalAdsProxy\AdsProxyServiceProvider;
use HongaYetu\InternalAdsProxy\Http\Controllers\MensagensPopupController;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * O proxy das mensagens em popup.
 *
 * Duas coisas que estes testes guardam: a conta Honga Yetu sai sempre da sessão
 * deste servidor, nunca do pedido; e as mensagens nunca partem a entrada da
 * app — desligado, sem conta ou com a central em baixo, a lista vem vazia e o
 * evento vem «ok».
 */
class MensagensPopupTest extends TestCase
{
    protected const CENTRAL = 'https://central.example/api/v1/servicos/mensagens-popup';

    /** Os atributos de quem está autenticado; `null` = ninguém. */
    protected static ?array $utilizador = ['id' => 7, 'honga_user_id' => 321];

    protected function getPackageProviders($app): array
    {
        return [AdsProxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Só o token dos anúncios: as mensagens usam-no por omissão.
        $app['config']->set('ads-proxy.token', 'chave-de-teste');
        $app['config']->set('ads-proxy.mensagens.base_url', self::CENTRAL);

        // O `auth:sanctum` do middleware por omissão, sem o Sanctum.
        $app['config']->set('auth.guards.sanctum', ['driver' => 'utilizador-de-teste']);
        $app['auth']->viaRequest(
            'utilizador-de-teste',
            fn () => self::$utilizador ? new GenericUser(self::$utilizador) : null,
        );
    }

    protected function setUp(): void
    {
        self::$utilizador = ['id' => 7, 'honga_user_id' => 321];

        parent::setUp();
    }

    protected function centralResponde(array $corpo = ['estado' => 'ok'], int $estado = 200): void
    {
        Http::fake(['*' => Http::response($corpo, $estado)]);
    }

    public function test_a_lista_vai_a_central_em_nome_da_conta_de_quem_esta_autenticado(): void
    {
        $this->centralResponde(['estado' => 'ok', 'mensagens' => [['id' => 9, 'titulo' => 'Novidade']]]);

        $this->getJson('/api/mensagens-popup?servico=humbi&superficie=app&versao_app=2.1.0&plataforma=android&empresa_id=5&escola_id=6')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => [['id' => 9, 'titulo' => 'Novidade']]]);

        Http::assertSentCount(1);
        Http::assertSent(function ($pedido) {
            parse_str((string) parse_url($pedido->url(), PHP_URL_QUERY), $query);

            return $pedido->method() === 'GET'
                && str_starts_with($pedido->url(), self::CENTRAL.'?')
                && $pedido->hasHeader('Authorization', 'Bearer chave-de-teste')
                && $query === [
                    'honga_user_id' => '321',
                    'servico' => 'humbi',
                    'superficie' => 'app',
                    'versao_app' => '2.1.0',
                    'plataforma' => 'android',
                ];
        });
    }

    public function test_o_honga_user_id_do_pedido_e_ignorado(): void
    {
        $this->centralResponde(['estado' => 'ok', 'mensagens' => []]);

        $this->getJson('/api/mensagens-popup?servico=humbi&superficie=app&honga_user_id=999')->assertOk();
        $this->postJson('/api/mensagens-popup/9/eventos', ['tipo' => 'vista', 'honga_user_id' => 999])->assertOk();

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($pedido) => str_contains($pedido->url().$pedido->body(), '999'));
        Http::assertSent(fn ($pedido) => $pedido->method() === 'POST' && $pedido['honga_user_id'] === 321);
    }

    public function test_sem_conta_honga_yetu_a_lista_vem_vazia_sem_chamar_a_central(): void
    {
        self::$utilizador = ['id' => 7, 'honga_user_id' => null];
        Http::fake();

        $this->getJson('/api/mensagens-popup?servico=humbi&superficie=app')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => []]);

        $this->postJson('/api/mensagens-popup/9/eventos', ['tipo' => 'vista'])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok']);

        Http::assertNothingSent();
    }

    public function test_sem_utilizador_nada_vai_a_central(): void
    {
        // Ninguém autenticado em guard nenhum: desde a 0.13.0 quem pede procura-se em todos
        // (`Identidade::quemPede`), e não só no utilizador que o pedido traz resolvido.
        self::$utilizador = null;
        Http::fake();

        $controller = $this->app->make(MensagensPopupController::class);

        $lista = $controller->index(Request::create('/api/mensagens-popup', 'GET', ['servico' => 'humbi', 'superficie' => 'app']));
        $evento = $controller->evento(Request::create('/api/mensagens-popup/9/eventos', 'POST', ['tipo' => 'vista']), '9');

        $this->assertSame(['estado' => 'ok', 'mensagens' => []], $lista->getData(true));
        $this->assertSame(['estado' => 'ok'], $evento->getData(true));
        Http::assertNothingSent();
    }

    public function test_as_rotas_por_omissao_exigem_sessao(): void
    {
        self::$utilizador = null;
        Http::fake();

        $this->getJson('/api/mensagens-popup?servico=humbi&superficie=app')->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_o_atributo_da_conta_e_configuravel(): void
    {
        self::$utilizador = ['id' => 7, 'conta_honga_id' => 88];
        config(['ads-proxy.mensagens.atributo_do_utilizador' => 'conta_honga_id']);
        $this->centralResponde(['estado' => 'ok', 'mensagens' => []]);

        $this->getJson('/api/mensagens-popup?servico=humbi&superficie=app')->assertOk();

        Http::assertSent(fn ($pedido) => str_contains($pedido->url(), 'honga_user_id=88'));
    }

    public function test_desligado_responde_vazio_sem_chamar_a_central(): void
    {
        config(['ads-proxy.mensagens.enabled' => false]);
        Http::fake();

        $this->getJson('/api/mensagens-popup?servico=humbi&superficie=app')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => []]);

        $this->postJson('/api/mensagens-popup/9/eventos', ['tipo' => 'vista'])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok']);

        Http::assertNothingSent();
    }

    public function test_a_central_em_baixo_nao_parte_a_entrada_da_app(): void
    {
        $this->centralResponde(['message' => 'Server Error'], 500);

        $this->getJson('/api/mensagens-popup?servico=humbi&superficie=app')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => []]);

        $this->postJson('/api/mensagens-popup/9/eventos', ['tipo' => 'vista'])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok']);
    }

    public function test_a_central_sem_resposta_nao_parte_a_entrada_da_app(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->getJson('/api/mensagens-popup?servico=humbi&superficie=app')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => []]);

        $this->postJson('/api/mensagens-popup/9/eventos', ['tipo' => 'vista'])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok']);
    }

    public function test_uma_recusa_da_central_a_lista_tambem_vem_vazia(): void
    {
        $this->centralResponde(['estado' => 'erro', 'texto' => 'Superfície inválida.'], 422);

        $this->getJson('/api/mensagens-popup?servico=humbi&superficie=relogio')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => []]);
    }

    public function test_o_evento_e_encaminhado_com_o_corpo_certo(): void
    {
        $this->centralResponde();

        $this->postJson('/api/mensagens-popup/9/eventos', [
            'tipo' => 'clique',
            'servico' => 'humbi',
            'superficie' => 'app',
            'versao_app' => '2.1.0',
            'plataforma' => 'ios',
            'botao' => 'principal',
            'destino' => 'https://humbi.example/promo',
            'segundos' => 12,
            'contexto_tipo' => 'loja',
            'contexto_id' => 4,
            'contexto_nome' => 'Loja do Kilamba',
            'empresa_id' => 5,
            'outro_campo' => 'fica de fora',
        ])->assertOk()->assertExactJson(['estado' => 'ok']);

        Http::assertSentCount(1);
        Http::assertSent(function ($pedido) {
            $corpo = $pedido->data();
            ksort($corpo);

            return $pedido->method() === 'POST'
                && $pedido->url() === self::CENTRAL.'/9/eventos'
                && $pedido->hasHeader('Authorization', 'Bearer chave-de-teste')
                && $pedido->isJson()
                && $corpo === [
                    'botao' => 'principal',
                    'contexto_id' => 4,
                    'contexto_nome' => 'Loja do Kilamba',
                    'contexto_tipo' => 'loja',
                    'destino' => 'https://humbi.example/promo',
                    'honga_user_id' => 321,
                    'plataforma' => 'ios',
                    'segundos' => 12,
                    'servico' => 'humbi',
                    'superficie' => 'app',
                    'tipo' => 'clique',
                    'versao_app' => '2.1.0',
                ];
        });
    }

    public function test_um_422_da_central_num_evento_passa_tal_e_qual(): void
    {
        $this->centralResponde(['estado' => 'erro', 'texto' => 'Esta mensagem não se pode dispensar.'], 422);

        $this->postJson('/api/mensagens-popup/9/eventos', ['tipo' => 'dispensada'])
            ->assertStatus(422)
            ->assertExactJson(['estado' => 'erro', 'texto' => 'Esta mensagem não se pode dispensar.']);
    }

    public function test_um_401_da_central_nao_chega_a_app(): void
    {
        $this->centralResponde(['message' => 'Unauthenticated.'], 401);

        $this->postJson('/api/mensagens-popup/9/eventos', ['tipo' => 'vista'])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok']);
    }

    public function test_a_mensagem_do_evento_tem_de_ser_numerica(): void
    {
        Http::fake();

        $this->postJson('/api/mensagens-popup/abc/eventos', ['tipo' => 'vista'])->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_um_servico_fora_dos_permitidos_recebe_a_lista_vazia(): void
    {
        config(['ads-proxy.mensagens.servicos_permitidos' => ['humbi', 'humbi-shop']]);
        $this->centralResponde(['estado' => 'ok', 'mensagens' => [['id' => 9]]]);

        $this->getJson('/api/mensagens-popup?servico=kanda&superficie=app')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => []]);

        $this->postJson('/api/mensagens-popup/9/eventos', ['tipo' => 'vista', 'servico' => 'kanda'])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok']);

        Http::assertNothingSent();

        $this->getJson('/api/mensagens-popup?servico=humbi-shop&superficie=app')
            ->assertOk()
            ->assertJsonPath('mensagens.0.id', 9);

        Http::assertSentCount(1);
    }

    public function test_sem_servico_no_pedido_usa_se_o_configurado_por_omissao(): void
    {
        config(['ads-proxy.mensagens.servico_por_omissao' => 'salu']);
        $this->centralResponde(['estado' => 'ok', 'mensagens' => []]);

        $this->getJson('/api/mensagens-popup?superficie=site')->assertOk();
        $this->postJson('/api/mensagens-popup/9/eventos', ['tipo' => 'vista'])->assertOk();

        Http::assertSent(fn ($pedido) => $pedido->method() === 'GET' && str_contains($pedido->url(), 'servico=salu'));
        Http::assertSent(fn ($pedido) => $pedido->method() === 'POST' && $pedido['servico'] === 'salu');
    }

    public function test_sem_servico_nenhum_a_lista_vem_vazia_sem_chamar_a_central(): void
    {
        Http::fake();

        $this->getJson('/api/mensagens-popup?superficie=app')
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'mensagens' => []]);

        Http::assertNothingSent();
    }

    public function test_o_limite_das_mensagens_tem_contador_proprio(): void
    {
        $this->assertContains('throttle:60,1,mensagens-popup', config('ads-proxy.mensagens.middleware'));
    }
}

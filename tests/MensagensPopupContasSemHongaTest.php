<?php

namespace HongaYetu\InternalAdsProxy\Tests;

use HongaYetu\InternalAdsProxy\AdsProxyServiceProvider;
use HongaYetu\InternalAdsProxy\Contratos\FiltroDeMensagens;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/** Deixa de fora quem tem `revendedor`. */
class FiltroSemRevendedor implements FiltroDeMensagens
{
    public function aceita(Request $request, mixed $utilizador): bool
    {
        return empty($utilizador->revendedor);
    }
}

class FiltroQueNaoImplementa {}

/**
 * As mensagens para quem não tem conta Honga Yetu (`mensagens.contas_sem_honga`) e o filtro de quem recebe
 * (`mensagens.filtro`), na API, na web e na directiva.
 */
class MensagensPopupContasSemHongaTest extends TestCase
{
    protected const CENTRAL = 'https://central.example/api/v1/servicos/mensagens-popup';

    protected static ?array $utilizador = null;

    protected function getPackageProviders($app): array
    {
        return [AdsProxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('ads-proxy.token', 'chave-de-teste');
        $app['config']->set('ads-proxy.mensagens.base_url', self::CENTRAL);
        $app['config']->set('ads-proxy.mensagens.servico_por_omissao', 'gpsyetu');
        $app['config']->set('ads-proxy.mensagens.web.zonas', [
            ['servico' => 'gpsyetu', 'superficie' => 'site', 'caminhos' => ['/'], 'anfitrioes' => []],
        ]);
        $app['config']->set('auth.guards.sanctum', ['driver' => 'utilizador-de-teste']);
        $app['auth']->viaRequest(
            'utilizador-de-teste',
            fn () => self::$utilizador ? new GenericUser(self::$utilizador) : null,
        );
    }

    protected function setUp(): void
    {
        self::$utilizador = ['id' => 'u-55', 'nome' => 'Mário Silva'];

        parent::setUp();

        Http::fake(['*' => Http::response(['estado' => 'ok', 'mensagens' => []])]);
    }

    protected function ligar(bool $ligado = true): void
    {
        config(['ads-proxy.mensagens.contas_sem_honga' => $ligado]);
    }

    protected function pedidoDaCentral(): ?PedidoHttp
    {
        return Http::recorded()->map(fn ($par) => $par[0])->first();
    }

    public function test_por_omissao_quem_nao_tem_conta_honga_continua_a_receber_a_lista_vazia(): void
    {
        $this->assertFalse(config('ads-proxy.mensagens.contas_sem_honga'));

        $this->getJson('/api/mensagens-popup?superficie=app')->assertOk()->assertExactJson(['estado' => 'ok', 'mensagens' => []]);

        Http::assertNothingSent();
    }

    public function test_ligado_manda_o_identificador_e_o_nome_do_servico_na_lista(): void
    {
        $this->ligar();

        $this->getJson('/api/mensagens-popup?superficie=app&conta_externa_id=inventado&honga_user_id=9')->assertOk();

        $pedido = $this->pedidoDaCentral();
        $this->assertSame('u-55', ($pedido->data()['conta_externa_id'] ?? null));
        $this->assertSame('Mário Silva', ($pedido->data()['conta_nome'] ?? null));
        $this->assertNull(($pedido->data()['honga_user_id'] ?? null));
    }

    public function test_o_identificador_numerico_vai_como_texto_e_o_nome_tenta_name(): void
    {
        $this->ligar();
        self::$utilizador = ['id' => 42, 'name' => 'Ana'];

        $this->getJson('/api/mensagens-popup?superficie=app')->assertOk();

        $pedido = $this->pedidoDaCentral();
        $this->assertSame('42', ($pedido->data()['conta_externa_id'] ?? null));
        $this->assertSame('Ana', ($pedido->data()['conta_nome'] ?? null));
    }

    public function test_o_atributo_do_nome_e_configuravel(): void
    {
        $this->ligar();
        config(['ads-proxy.mensagens.atributo_do_nome' => 'perfil.apelido']);
        self::$utilizador = ['id' => 5, 'nome' => 'Ignorado', 'perfil' => ['apelido' => 'Mana']];

        $this->getJson('/api/mensagens-popup?superficie=app')->assertOk();

        $this->assertSame('Mana', $this->pedidoDaCentral()->data()['conta_nome']);
    }

    public function test_com_conta_honga_continua_a_mandar_so_o_honga_user_id(): void
    {
        $this->ligar();
        self::$utilizador = ['id' => 'u-55', 'nome' => 'Mário', 'honga_user_id' => 321];

        $this->getJson('/api/mensagens-popup?superficie=app')->assertOk();

        $pedido = $this->pedidoDaCentral();
        $this->assertSame(321, ($pedido->data()['honga_user_id'] ?? null));
        $this->assertNull(($pedido->data()['conta_externa_id'] ?? null));
        $this->assertNull(($pedido->data()['conta_nome'] ?? null));
    }

    public function test_um_identificador_que_a_central_recusaria_nao_segue(): void
    {
        $this->ligar();
        self::$utilizador = ['id' => 'tem espaços', 'nome' => 'X'];

        $this->getJson('/api/mensagens-popup?superficie=app')->assertOk()->assertExactJson(['estado' => 'ok', 'mensagens' => []]);

        Http::assertNothingSent();
    }

    public function test_o_evento_leva_a_conta_do_servico_e_o_pedido_nao_a_pode_trocar(): void
    {
        $this->ligar();

        $this->postJson('/api/mensagens-popup/12/eventos', ['tipo' => 'vista', 'servico' => 'gpsyetu', 'conta_externa_id' => 'outro', 'conta_nome' => 'Falso', 'honga_user_id' => 9])->assertOk();

        $pedido = $this->pedidoDaCentral();
        $this->assertSame(self::CENTRAL.'/12/eventos', $pedido->url());
        $this->assertSame('u-55', ($pedido->data()['conta_externa_id'] ?? null));
        $this->assertSame('Mário Silva', ($pedido->data()['conta_nome'] ?? null));
        $this->assertNull(($pedido->data()['honga_user_id'] ?? null));
    }

    public function test_o_filtro_deixa_de_fora_a_lista_e_o_evento(): void
    {
        $this->ligar();
        config(['ads-proxy.mensagens.filtro' => FiltroSemRevendedor::class]);
        self::$utilizador = ['id' => 'u-1', 'nome' => 'Cliente', 'revendedor' => 77];

        $this->getJson('/api/mensagens-popup?superficie=app')->assertOk()->assertExactJson(['estado' => 'ok', 'mensagens' => []]);
        $this->postJson('/api/mensagens-popup/12/eventos', ['tipo' => 'vista'])->assertOk()->assertExactJson(['estado' => 'ok']);
        Http::assertNothingSent();

        self::$utilizador = ['id' => 'u-2', 'nome' => 'Directo'];
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/mensagens-popup?superficie=app')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_o_filtro_tambem_vale_para_quem_tem_conta_honga(): void
    {
        config(['ads-proxy.mensagens.filtro' => FiltroSemRevendedor::class]);
        self::$utilizador = ['id' => 'u-1', 'honga_user_id' => 321, 'revendedor' => 77];

        $this->getJson('/api/mensagens-popup?superficie=app')->assertOk()->assertExactJson(['estado' => 'ok', 'mensagens' => []]);

        Http::assertNothingSent();
    }

    public function test_um_filtro_que_nao_se_resolve_recusa_em_vez_de_partir(): void
    {
        $this->ligar();

        foreach (['Nao\\Existe', FiltroQueNaoImplementa::class] as $classe) {
            config(['ads-proxy.mensagens.filtro' => $classe]);

            $this->getJson('/api/mensagens-popup?superficie=app')->assertOk()->assertExactJson(['estado' => 'ok', 'mensagens' => []]);
        }

        Http::assertNothingSent();
    }

    public function test_na_web_a_lista_usa_a_conta_do_servico_e_o_filtro(): void
    {
        $this->ligar();

        $this->actingAs(new GenericUser(['id' => 'u-55', 'nome' => 'Mário Silva']))
            ->getJson('/mensagens-popup?servico=gpsyetu&superficie=site')->assertOk();

        $this->assertSame('u-55', $this->pedidoDaCentral()->data()['conta_externa_id']);
    }

    public function test_a_directiva_escreve_para_quem_tem_conta_do_servico_so_com_a_opcao_ligada_e_o_filtro_aceita(): void
    {
        $this->actingAs(new GenericUser(['id' => 'u-55', 'nome' => 'Mário']));

        $this->assertSame('', Blade::render('@mensagensPopupHonga'));

        $this->ligar();
        $this->assertStringContainsString('<script defer', Blade::render('@mensagensPopupHonga'));

        config(['ads-proxy.mensagens.filtro' => FiltroSemRevendedor::class]);
        $this->assertStringContainsString('<script defer', Blade::render('@mensagensPopupHonga'));

        $this->actingAs(new GenericUser(['id' => 'u-56', 'nome' => 'Cliente', 'revendedor' => 3]));
        $this->assertSame('', Blade::render('@mensagensPopupHonga'));
    }
}

<?php

namespace HongaYetu\InternalAdsProxy\Tests;

use HongaYetu\InternalAdsProxy\AdsProxyServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * O `/serve`: o que o proxy deixa passar para a central e o que devolve.
 *
 * O `validate` só devolve os campos que conhece. Um campo novo do pedido que
 * não esteja nas regras desaparece em silêncio, e a funcionalidade nunca chega
 * a sair da app (foi assim com as `keywords`).
 */
class AdsProxyServeTest extends TestCase
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
        $app['config']->set('ads-proxy.middleware', []);
    }

    public function test_o_aceita_nativo_segue_para_a_central_e_o_nativo_volta_tal_e_qual(): void
    {
        $nativo = [
            'titulo' => 'ERP para a tua PME',
            'descricao' => 'Facturação AGT, stock e RH num só sítio.',
            'cta' => ['codigo' => 'saber_mais', 'texto' => 'Saber mais'],
            'imagem' => [
                'url' => 'https://cdn.example/1200.webp',
                'largura' => 1200,
                'altura' => 628,
                'variantes' => [['largura' => 480, 'url' => 'https://cdn.example/480.webp']],
            ],
            'logo_url' => 'https://cdn.example/logo.webp',
            'anunciante' => 'Logigate',
            'cor_marca' => '#1D4ED8',
        ];

        Http::fake([
            '*/serve' => Http::response([
                'estado' => 'ok',
                'data' => [
                    'anuncio' => ['id' => 7, 'nome' => 'ERP', 'assets' => [], 'nativo' => $nativo],
                    'tokens' => ['impression' => 'imp', 'click' => 'clk'],
                    'ttl' => 300,
                ],
            ]),
        ]);

        $resposta = $this->postJson($this->enderecoDoServe(), [
            'espaco_slug' => 'humbi_shop',
            'sublocal' => 'lista_produtos',
            'aceita_nativo' => true,
        ]);

        $resposta->assertOk();
        $resposta->assertJsonPath('data.anuncio.nativo', $nativo);
        $resposta->assertJsonPath('data.anuncio.assets', []);

        Http::assertSent(fn ($pedido) => $pedido['espaco_slug'] === 'humbi_shop'
            && $pedido['aceita_nativo'] === true);
    }

    public function test_sem_o_campo_o_pedido_segue_como_antes(): void
    {
        Http::fake(['*/serve' => Http::response(['estado' => 'ok', 'data' => null])]);

        $this->postJson($this->enderecoDoServe(), ['espaco_slug' => 'humbi_shop'])->assertOk();

        Http::assertSent(fn ($pedido) => ! array_key_exists('aceita_nativo', $pedido->data()));
    }

    public function test_um_aceita_nativo_que_nao_e_booleano_e_recusado(): void
    {
        Http::fake();

        $this->postJson($this->enderecoDoServe(), [
            'espaco_slug' => 'humbi_shop',
            'aceita_nativo' => 'talvez',
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * A conta de quem vê o anúncio sai sempre da sessão deste servidor. Um `honga_user_id` escrito pelo
     * cliente no corpo deixava qualquer pessoa pedir anúncios (e alimentar o perfil) em nome de outra.
     */
    public function test_um_honga_user_id_vindo_do_cliente_nunca_segue_para_a_central(): void
    {
        Http::fake(['*/serve' => Http::response(['estado' => 'ok', 'data' => null])]);

        $this->postJson($this->enderecoDoServe(), ['espaco_slug' => 'humbi_shop', 'honga_user_id' => 999])->assertOk();

        Http::assertSent(fn ($pedido) => ! array_key_exists('honga_user_id', $pedido->data()));
    }

    public function test_com_sessao_vai_o_honga_user_id_da_conta_e_nao_o_do_corpo(): void
    {
        Http::fake(['*/serve' => Http::response(['estado' => 'ok', 'data' => null])]);

        $utilizador = (new \Illuminate\Foundation\Auth\User)->forceFill(['id' => 31, 'honga_user_id' => 77]);

        $this->actingAs($utilizador)
            ->postJson($this->enderecoDoServe(), ['espaco_slug' => 'humbi_shop', 'honga_user_id' => 999])
            ->assertOk();

        Http::assertSent(fn ($pedido) => $pedido['honga_user_id'] === 77);
    }

    public function test_com_sessao_sem_conta_ligada_o_do_corpo_tambem_nao_segue(): void
    {
        Http::fake(['*/serve' => Http::response(['estado' => 'ok', 'data' => null])]);

        $utilizador = (new \Illuminate\Foundation\Auth\User)->forceFill(['id' => 31]);

        $this->actingAs($utilizador)
            ->postJson($this->enderecoDoServe(), ['espaco_slug' => 'humbi_shop', 'honga_user_id' => 999])
            ->assertOk();

        Http::assertSent(fn ($pedido) => ! array_key_exists('honga_user_id', $pedido->data()));
    }

    protected function enderecoDoServe(): string
    {
        return '/'.trim((string) config('ads-proxy.route_prefix', 'api/ads-proxy'), '/').'/serve';
    }
}

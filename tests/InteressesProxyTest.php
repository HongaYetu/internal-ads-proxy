<?php

namespace HongaYetu\InternalAdsProxy\Tests;

use HongaYetu\InternalAdsProxy\AdsProxyServiceProvider;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * Os sinais de interesse: `POST {prefixo do proxy}/interesses`.
 *
 * É fire-and-forget. Tirando um corpo inválido, a resposta é sempre 200: sem sessão, sem conta Honga Yetu
 * ligada, desligado ou com a central em falha, vem `registados: 0` e o ecrã do cliente segue.
 */
class InteressesProxyTest extends TestCase
{
    protected const CENTRAL = 'https://central.example/api/v1/servicos/interesses';

    protected function getPackageProviders($app): array
    {
        return [AdsProxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ads-proxy.token', 'chave-de-teste');
        $app['config']->set('ads-proxy.interesses.base_url', self::CENTRAL);
    }

    protected function utilizador(?int $hongaUserId): User
    {
        $utilizador = new User;
        $utilizador->forceFill(['id' => 31, 'honga_user_id' => $hongaUserId]);

        return $utilizador;
    }

    protected function sinal(array $corpo = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/ads-proxy/interesses', $corpo + ['tipo' => 'pesquisa', 'temas' => ['telemoveis', 'informatica']]);
    }

    public function test_com_conta_ligada_reencaminha_com_o_honga_user_id_do_servidor(): void
    {
        Http::fake([self::CENTRAL => Http::response(['estado' => 'ok', 'registados' => 2, 'ignorados' => 0])]);

        $this->actingAs($this->utilizador(77))
            ->sinal(['honga_user_id' => 999])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'registados' => 2, 'ignorados' => 0]);

        Http::assertSent(fn ($pedido) => $pedido->url() === self::CENTRAL
            && $pedido->hasHeader('Authorization', 'Bearer chave-de-teste')
            && $pedido->data() === ['honga_user_id' => 77, 'tipo' => 'pesquisa', 'temas' => ['telemoveis', 'informatica']]);
    }

    public function test_sem_sessao_responde_zero_sem_chamar_a_central_mesmo_com_honga_user_id_no_corpo(): void
    {
        Http::fake();

        $this->sinal(['honga_user_id' => 999])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'registados' => 0]);

        Http::assertNothingSent();
    }

    public function test_sem_conta_honga_yetu_ligada_responde_zero_sem_chamar_a_central(): void
    {
        Http::fake();

        $this->actingAs($this->utilizador(null))
            ->sinal(['honga_user_id' => 999])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'registados' => 0]);

        Http::assertNothingSent();
    }

    public function test_a_falha_da_central_nunca_chega_ao_cliente(): void
    {
        Http::fake([self::CENTRAL => Http::response(['estado' => 'erro', 'texto' => 'Rebentou.'], 500)]);

        $this->actingAs($this->utilizador(77))->sinal()->assertOk()->assertExactJson(['estado' => 'ok', 'registados' => 0]);
    }

    public function test_sem_rede_tambem_responde_zero(): void
    {
        Http::fake(fn () => throw new ConnectionException('tempo esgotado'));

        $this->actingAs($this->utilizador(77))->sinal()->assertOk()->assertExactJson(['estado' => 'ok', 'registados' => 0]);
    }

    public function test_o_motivo_da_central_passa(): void
    {
        Http::fake([self::CENTRAL => Http::response(['estado' => 'ok', 'registados' => 0, 'ignorados' => 2, 'motivo' => 'desligado'])]);

        $this->actingAs($this->utilizador(77))
            ->sinal()
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'registados' => 0, 'ignorados' => 2, 'motivo' => 'desligado']);
    }

    public function test_desligado_responde_zero_sem_chamar_a_central(): void
    {
        config(['ads-proxy.interesses.enabled' => false]);
        Http::fake();

        $this->actingAs($this->utilizador(77))->sinal()->assertOk()->assertExactJson(['estado' => 'ok', 'registados' => 0]);

        Http::assertNothingSent();
    }

    public function test_o_tempo_de_espera_e_curto(): void
    {
        Http::fake([self::CENTRAL => Http::response(['estado' => 'ok', 'registados' => 1])]);

        $this->actingAs($this->utilizador(77))->sinal()->assertOk();

        $this->assertSame(3, config('ads-proxy.interesses.timeout'));
    }

    /** @dataProvider corposInvalidos */
    #[\PHPUnit\Framework\Attributes\DataProvider('corposInvalidos')]
    public function test_um_corpo_invalido_e_recusado_com_422(array $corpo): void
    {
        Http::fake();

        $this->actingAs($this->utilizador(77))
            ->postJson('/api/ads-proxy/interesses', $corpo)
            ->assertStatus(422)
            ->assertJsonPath('estado', 'erro')
            ->assertJsonStructure(['texto']);

        Http::assertNothingSent();
    }

    public static function corposInvalidos(): array
    {
        return [
            'tipo fora da lista' => [['tipo' => 'clique_anuncio', 'temas' => ['a']]],
            'sem tipo' => [['temas' => ['a']]],
            'sem temas' => [['tipo' => 'compra']],
            'temas vazios' => [['tipo' => 'compra', 'temas' => []]],
            'onze temas' => [['tipo' => 'compra', 'temas' => array_fill(0, 11, 'a')]],
            'tema com 61 letras' => [['tipo' => 'compra', 'temas' => [str_repeat('a', 61)]]],
            'tema que não é texto' => [['tipo' => 'compra', 'temas' => [['x' => 1]]]],
        ];
    }
}

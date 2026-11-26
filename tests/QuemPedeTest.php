<?php

namespace HongaYetu\InternalAdsProxy\Tests;

use HongaYetu\InternalAdsProxy\AdsProxyServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

/**
 * Quem pede: a conta Honga Yetu tem de chegar à central pelas duas superfícies.
 *
 *  - **A app** manda um Bearer. As rotas dos anúncios servem visitantes e por isso não levam `auth`; sem
 *    ele, o guard por omissão (sessão) não vê o Bearer, e o `honga_user_id` nunca seguia.
 *  - **O site** manda o cookie de sessão. Só há sessão com o grupo `web`, que as rotas `api/…` não têm:
 *    daí as rotas web (`anuncios-proxy/*`).
 *
 * O `sanctum` daqui é um guard de teste que lê o Bearer, como o verdadeiro; a sessão é a do `web`.
 */
class QuemPedeTest extends TestCase
{
    protected const SERVE = 'https://anuncios.example/api/v2/ads/serve';

    protected const INTERESSES = 'https://central.example/api/v1/servicos/interesses';

    protected function getPackageProviders($app): array
    {
        return [AdsProxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('ads-proxy.base_url', 'https://anuncios.example/api/v2/ads');
        $app['config']->set('ads-proxy.token', 'chave-de-teste');
        $app['config']->set('ads-proxy.interesses.base_url', self::INTERESSES);

        // Como no Humbi, no TicketVerse e na Sócia: as rotas da API sem `auth`.
        $app['config']->set('ads-proxy.middleware', ['throttle:60,1,anuncios']);
        // As rotas do site ligadas.
        $app['config']->set('ads-proxy.web.auto_register_routes', true);

        // O Bearer `token-da-conta-77` é da conta 77; qualquer outro não é de ninguém.
        $app['config']->set('auth.guards.sanctum', ['driver' => 'bearer-de-teste']);
        $app['auth']->viaRequest('bearer-de-teste', fn (Request $pedido) => match ($pedido->bearerToken()) {
            'token-da-conta-77' => new GenericUser(['id' => 5, 'honga_user_id' => 77]),
            'token-sem-conta-honga' => new GenericUser(['id' => 6, 'honga_user_id' => null]),
            default => null,
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            self::SERVE => Http::response(['estado' => 'ok', 'data' => null]),
            self::INTERESSES => Http::response(['estado' => 'ok', 'registados' => 1, 'ignorados' => 0]),
        ]);
    }

    protected function daSessao(?int $hongaUserId = 88): User
    {
        return (new User)->forceFill(['id' => 9, 'honga_user_id' => $hongaUserId]);
    }

    protected function assertSeguiuComAConta(string $endereco, int $hongaUserId): void
    {
        Http::assertSent(fn ($pedido) => $pedido->url() === $endereco && ($pedido->data()['honga_user_id'] ?? null) === $hongaUserId);
    }

    protected function assertSeguiuSemConta(string $endereco): void
    {
        Http::assertSent(fn ($pedido) => $pedido->url() === $endereco && ! array_key_exists('honga_user_id', $pedido->data()));
    }

    // ───────────── a app: Bearer, rota da API sem `auth` ─────────────

    public function test_app_com_bearer_sem_auth_no_middleware_o_serve_leva_a_conta(): void
    {
        $this->withToken('token-da-conta-77')
            ->postJson('/api/ads-proxy/serve', ['espaco_slug' => 'humbi_shop', 'honga_user_id' => 999])
            ->assertOk();

        $this->assertSeguiuComAConta(self::SERVE, 77);
    }

    public function test_app_com_bearer_os_interesses_levam_a_conta(): void
    {
        $this->withToken('token-da-conta-77')
            ->postJson('/api/ads-proxy/interesses', ['tipo' => 'compra', 'temas' => ['telemoveis'], 'honga_user_id' => 999])
            ->assertOk()
            ->assertJsonPath('registados', 1);

        $this->assertSeguiuComAConta(self::INTERESSES, 77);
    }

    public function test_app_com_bearer_de_uma_conta_sem_honga_yetu_segue_como_visitante(): void
    {
        $this->withToken('token-sem-conta-honga')
            ->postJson('/api/ads-proxy/serve', ['espaco_slug' => 'humbi_shop', 'honga_user_id' => 999])
            ->assertOk();

        $this->assertSeguiuSemConta(self::SERVE);
    }

    // ───────────── o site: sessão, rotas web ─────────────

    public function test_site_com_sessao_pela_rota_web_o_serve_leva_a_conta(): void
    {
        $this->actingAs($this->daSessao(), 'web')
            ->postJson('/anuncios-proxy/serve', ['espaco_slug' => 'humbi_shop', 'honga_user_id' => 999])
            ->assertOk();

        $this->assertSeguiuComAConta(self::SERVE, 88);
    }

    public function test_site_com_sessao_pela_rota_web_os_interesses_levam_a_conta(): void
    {
        $this->actingAs($this->daSessao(), 'web')
            ->postJson('/anuncios-proxy/interesses', ['tipo' => 'pesquisa', 'temas' => ['bilhetes']])
            ->assertOk()
            ->assertJsonPath('registados', 1);

        $this->assertSeguiuComAConta(self::INTERESSES, 88);
    }

    public function test_as_rotas_web_levam_o_grupo_web_e_nao_levam_auth(): void
    {
        $rotas = collect($this->app['router']->getRoutes()->getRoutes())
            ->filter(fn ($rota) => str_starts_with($rota->uri(), 'anuncios-proxy/'));

        $this->assertEqualsCanonicalizing(
            ['anuncios-proxy/serve', 'anuncios-proxy/impression', 'anuncios-proxy/click', 'anuncios-proxy/video-event', 'anuncios-proxy/interesses', 'anuncios-proxy/click/{token}'],
            $rotas->map->uri()->values()->all(),
        );

        foreach ($rotas as $rota) {
            $this->assertContains('web', $rota->gatherMiddleware(), $rota->uri());
            $this->assertEmpty(array_filter($rota->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'auth')), $rota->uri());
            $this->assertStringEndsNotWith('.js', $rota->uri());
        }
    }

    public function test_a_rota_web_do_site_recusa_um_post_sem_csrf(): void
    {
        // O `web` traz a verificação de CSRF: é por isso que as apps ficam nas rotas `api/…`. (Em testes o
        // Laravel salta-a; aqui volta a ligar-se para provar que a rota a leva.)
        $this->app['env'] = 'production';
        $this->app->instance('env', 'production');

        $this->post('/anuncios-proxy/serve', ['espaco_slug' => 'humbi_shop'])->assertStatus(419);

        Http::assertNothingSent();
    }

    public function test_sem_as_rotas_web_ligadas_elas_nao_existem(): void
    {
        // Por omissão estão desligadas: um serviço que só actualiza o package não ganha rotas novas.
        $this->assertFalse((bool) (require __DIR__.'/../config/ads-proxy.php')['web']['auto_register_routes']);
    }

    // ───────────── visitantes ─────────────

    public function test_visitante_na_api_serve_sem_conta_e_interesses_a_zero(): void
    {
        $this->postJson('/api/ads-proxy/serve', ['espaco_slug' => 'humbi_shop', 'honga_user_id' => 999])->assertOk();
        $this->assertSeguiuSemConta(self::SERVE);

        $this->postJson('/api/ads-proxy/interesses', ['tipo' => 'ver_item', 'temas' => ['x'], 'honga_user_id' => 999])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'registados' => 0]);

        Http::assertNotSent(fn ($pedido) => $pedido->url() === self::INTERESSES);
    }

    public function test_visitante_no_site_serve_sem_conta_e_interesses_a_zero(): void
    {
        $this->postJson('/anuncios-proxy/serve', ['espaco_slug' => 'humbi_shop', 'honga_user_id' => 999])->assertOk();
        $this->assertSeguiuSemConta(self::SERVE);

        $this->postJson('/anuncios-proxy/interesses', ['tipo' => 'ver_item', 'temas' => ['x'], 'honga_user_id' => 999])
            ->assertOk()
            ->assertExactJson(['estado' => 'ok', 'registados' => 0]);

        Http::assertNotSent(fn ($pedido) => $pedido->url() === self::INTERESSES);
    }

    public function test_um_bearer_que_nao_presta_segue_como_visitante(): void
    {
        $this->withToken('token-velho')
            ->postJson('/api/ads-proxy/serve', ['espaco_slug' => 'humbi_shop'])
            ->assertOk();

        $this->assertSeguiuSemConta(self::SERVE);
    }

    // ───────────── a ordem ─────────────

    public function test_os_guards_configurados_vem_primeiro(): void
    {
        config(['ads-proxy.guards' => ['cliente']]);
        config(['auth.guards.cliente' => ['driver' => 'cliente-de-teste']]);
        $this->app['auth']->viaRequest('cliente-de-teste', fn () => new GenericUser(['id' => 1, 'honga_user_id' => 55]));

        $this->withToken('token-da-conta-77')
            ->postJson('/api/ads-proxy/serve', ['espaco_slug' => 'humbi_shop'])
            ->assertOk();

        $this->assertSeguiuComAConta(self::SERVE, 55);
    }

    public function test_um_guard_configurado_que_nao_existe_nao_parte_nada(): void
    {
        config(['ads-proxy.guards' => ['nao-existe']]);

        $this->withToken('token-da-conta-77')
            ->postJson('/api/ads-proxy/serve', ['espaco_slug' => 'humbi_shop'])
            ->assertOk();

        $this->assertSeguiuComAConta(self::SERVE, 77);
    }

    public function test_o_atributo_da_conta_e_o_configurado(): void
    {
        // Nem todos os serviços lhe chamam `honga_user_id` (o GPS Yetu chama `honga_id`).
        config(['ads-proxy.mensagens.atributo_do_utilizador' => 'honga_id']);

        $this->actingAs((new User)->forceFill(['id' => 9, 'honga_id' => 44]), 'web')
            ->postJson('/anuncios-proxy/serve', ['espaco_slug' => 'gps_yetu'])
            ->assertOk();

        $this->assertSeguiuComAConta(self::SERVE, 44);
    }
}

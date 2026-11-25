<?php

namespace HongaYetu\InternalAdsProxy\Tests;

use HongaYetu\InternalAdsProxy\AdsProxyServiceProvider;
use HongaYetu\InternalAdsProxy\Contratos\OrigemDoFeedback;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;

class OrigemDeTeste implements OrigemDoFeedback
{
    public function origem(Request $request, mixed $utilizador): ?array
    {
        return $utilizador === null ? null : ['tipo' => 'revendedor', 'id' => 77, 'nome' => 'Rastreio Kianda'];
    }
}

/** O proxy do feedback: o encaminhamento, a conta que sai do servidor, as capturas e as falhas. */
class FeedbackProxyTest extends TestCase
{
    protected const CENTRAL = 'https://central.example/api/v1/servicos/feedback';

    protected static ?array $utilizador = null;

    protected function getPackageProviders($app): array
    {
        return [AdsProxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('ads-proxy.token', 'chave-de-teste');
        $app['config']->set('ads-proxy.feedback.base_url', self::CENTRAL);
        $app['config']->set('ads-proxy.feedback.servico_por_omissao', 'gpsyetu');
        $app['config']->set('ads-proxy.feedback.guard', 'sanctum');
        $app['config']->set('auth.guards.sanctum', ['driver' => 'utilizador-de-teste']);
        $app['auth']->viaRequest(
            'utilizador-de-teste',
            fn () => self::$utilizador ? new GenericUser(self::$utilizador) : null,
        );
    }

    protected function setUp(): void
    {
        self::$utilizador = null;

        parent::setUp();

        Storage::fake('public');
        Http::fake(['*' => Http::response(['estado' => 'ok'])]);
    }

    /** Troca o que a central responde (um `Http::fake` novo não ganha ao anterior). */
    protected function centralResponde(mixed $resposta): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*' => $resposta]);
    }

    /** @return array<string, mixed> */
    protected function corpo(array $extra = []): array
    {
        return ['texto' => 'O mapa demora a abrir.', 'tipo' => 'problema', 'estrelas' => 2, 'superficie' => 'app', ...$extra];
    }

    protected function enviado(): PedidoHttp
    {
        return Http::recorded()->map(fn ($par) => $par[0])->first();
    }

    public function test_as_rotas_ficam_registadas_sem_auth_e_com_limites_com_nome(): void
    {
        $rotas = [
            'feedback.enviar' => ['api/feedback', ['throttle:10,1,feedback']],
            'feedback.capturas' => ['api/feedback/capturas', ['throttle:12,1,feedback-capturas']],
            'feedback.web.enviar' => ['feedback', ['web', 'throttle:10,1,feedback-web']],
            'feedback.web.capturas' => ['feedback/capturas', ['web', 'throttle:12,1,feedback-capturas-web']],
        ];

        foreach ($rotas as $nome => [$uri, $middleware]) {
            $rota = Route::getRoutes()->getByName($nome);

            $this->assertNotNull($rota, $nome);
            $this->assertSame($uri, $rota->uri());
            $this->assertSame(['POST'], $rota->methods());
            $this->assertSame($middleware, $rota->gatherMiddleware(), $nome);
            $this->assertStringEndsNotWith('.js', $uri);
            $this->assertStringEndsNotWith('.css', $uri);
        }
    }

    public function test_um_visitante_envia_e_a_central_recebe_so_o_que_a_app_pode_mandar(): void
    {
        $this->postJson('/api/feedback', $this->corpo([
            'versao_app' => '2.1.0', 'plataforma' => 'android', 'ecra' => 'Mapa', 'aparelho' => 'Tecno',
            'quer_resposta' => true, 'contacto_tipo' => 'email', 'contacto' => 'a@b.ao',
            'honga_user_id' => 999, 'conta_externa_id' => 'falso', 'conta_nome' => 'Falso',
            'origem_tipo' => 'x', 'origem_id' => '1', 'origem_nome' => 'Falso', 'ip_resumo' => 'x',
        ]))->assertOk()->assertExactJson(['estado' => 'ok']);

        $pedido = $this->enviado();
        $this->assertSame(self::CENTRAL, $pedido->url());
        $this->assertTrue($pedido->hasHeader('Authorization', 'Bearer chave-de-teste'));
        $this->assertEquals([
            'texto' => 'O mapa demora a abrir.', 'tipo' => 'problema', 'estrelas' => 2, 'superficie' => 'app',
            'versao_app' => '2.1.0', 'plataforma' => 'android', 'ecra' => 'Mapa', 'aparelho' => 'Tecno',
            'quer_resposta' => true, 'contacto_tipo' => 'email', 'contacto' => 'a@b.ao', 'servico' => 'gpsyetu',
        ], $pedido->data());
    }

    public function test_o_servico_do_pedido_ou_o_de_omissao_e_a_lista_permitida(): void
    {
        $this->postJson('/api/feedback', $this->corpo(['servico' => 'humbi']))->assertOk();
        $this->assertSame('humbi', $this->enviado()->data()['servico']);

        config(['ads-proxy.feedback.servicos_permitidos' => ['gpsyetu']]);
        $this->postJson('/api/feedback', $this->corpo(['servico' => 'humbi']))->assertStatus(422)->assertJsonPath('estado', 'erro');
        Http::assertSentCount(1);

        // Sem lista própria, vale a das mensagens.
        config(['ads-proxy.feedback.servicos_permitidos' => [], 'ads-proxy.mensagens.servicos_permitidos' => ['gpsyetu']]);
        $this->postJson('/api/feedback', $this->corpo(['servico' => 'humbi']))->assertStatus(422);

        config(['ads-proxy.feedback.servico_por_omissao' => null, 'ads-proxy.mensagens.servico_por_omissao' => null]);
        $this->postJson('/api/feedback', $this->corpo())->assertStatus(422);
        Http::assertSentCount(1);
    }

    public function test_quem_tem_conta_honga_vai_com_o_honga_user_id(): void
    {
        self::$utilizador = ['id' => 'u-1', 'nome' => 'Mário', 'honga_user_id' => 321];

        $this->postJson('/api/feedback', $this->corpo())->assertOk();

        $dados = $this->enviado()->data();
        $this->assertSame(321, $dados['honga_user_id']);
        $this->assertArrayNotHasKey('conta_externa_id', $dados);
    }

    public function test_quem_nao_tem_conta_honga_vai_com_a_conta_do_servico_por_omissao(): void
    {
        self::$utilizador = ['id' => 55, 'nome' => 'Mário Silva'];

        $this->postJson('/api/feedback', $this->corpo())->assertOk();

        $dados = $this->enviado()->data();
        $this->assertSame('55', $dados['conta_externa_id']);
        $this->assertSame('Mário Silva', $dados['conta_nome']);
        $this->assertArrayNotHasKey('honga_user_id', $dados);
    }

    public function test_com_a_opcao_desligada_a_conta_do_servico_nao_vai(): void
    {
        config(['ads-proxy.feedback.contas_sem_honga' => false]);
        self::$utilizador = ['id' => 55, 'nome' => 'Mário'];

        $this->postJson('/api/feedback', $this->corpo())->assertOk();

        $this->assertArrayNotHasKey('conta_externa_id', $this->enviado()->data());
    }

    public function test_enviar_como_zero_nao_manda_conta_nem_origem(): void
    {
        config(['ads-proxy.feedback.origem' => OrigemDeTeste::class]);
        self::$utilizador = ['id' => 55, 'nome' => 'Mário', 'honga_user_id' => 321];

        $this->postJson('/api/feedback', $this->corpo(['enviar_como' => 0]))->assertOk();

        $dados = $this->enviado()->data();
        $this->assertSame(0, $dados['enviar_como']);
        foreach (['honga_user_id', 'conta_externa_id', 'conta_nome'] as $chave) {
            $this->assertArrayNotHasKey($chave, $dados);
        }
    }

    public function test_a_origem_vem_da_classe_do_servico_e_nunca_do_pedido(): void
    {
        config(['ads-proxy.feedback.origem' => OrigemDeTeste::class]);
        self::$utilizador = ['id' => 55, 'nome' => 'Mário'];

        $this->postJson('/api/feedback', $this->corpo(['origem_nome' => 'Falso']))->assertOk();

        $dados = $this->enviado()->data();
        $this->assertSame('revendedor', $dados['origem_tipo']);
        $this->assertSame('77', $dados['origem_id']);
        $this->assertSame('Rastreio Kianda', $dados['origem_nome']);
    }

    public function test_uma_classe_de_origem_mal_posta_nao_parte_o_envio(): void
    {
        config(['ads-proxy.feedback.origem' => 'Nao\\Existe']);
        Log::shouldReceive('warning')->once()->with('hongayetu.feedback.origem_falhou', \Mockery::type('array'));

        $this->postJson('/api/feedback', $this->corpo())->assertOk();

        $this->assertArrayNotHasKey('origem_nome', $this->enviado()->data());
    }

    public function test_a_armadilha_responde_ok_sem_chamar_a_central(): void
    {
        $this->postJson('/api/feedback', $this->corpo(['site' => 'http://spam.example']))->assertOk()->assertExactJson(['estado' => 'ok']);

        Http::assertNothingSent();
    }

    public function test_a_captura_guarda_o_ficheiro_e_o_envio_troca_o_id_pelo_endereco_https(): void
    {
        $id = $this->post('/api/feedback/capturas', ['imagem' => UploadedFile::fake()->image('ecra.png', 200, 200)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('estado', 'ok')->json('captura');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}\.png$/', $id);
        Storage::disk('public')->assertExists('feedback/capturas/'.$id);

        $this->postJson('/api/feedback', $this->corpo(['capturas' => [$id, 'inexistente.png', '../../.env', str_repeat('a', 40).'.png']]))->assertOk();

        $capturas = $this->enviado()->data()['capturas'];
        $this->assertCount(1, $capturas);
        $this->assertStringStartsWith('https://', $capturas[0]);
        $this->assertStringEndsWith('/storage/feedback/capturas/'.$id, $capturas[0]);
    }

    public function test_so_seguem_tres_capturas(): void
    {
        $ids = [];
        foreach (range(1, 4) as $i) {
            $ids[] = $this->post('/api/feedback/capturas', ['imagem' => UploadedFile::fake()->image("{$i}.jpg")], ['Accept' => 'application/json'])->json('captura');
        }

        $this->postJson('/api/feedback', $this->corpo(['capturas' => $ids]))->assertOk();

        $this->assertCount(3, $this->enviado()->data()['capturas']);
    }

    public function test_a_captura_recusa_o_que_nao_e_imagem_ou_passa_dos_5_mb(): void
    {
        $this->post('/api/feedback/capturas', ['imagem' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('estado', 'erro');
        $this->post('/api/feedback/capturas', ['imagem' => UploadedFile::fake()->image('grande.jpg')->size(6000)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('texto', 'A captura passa dos 5 MB. Escolhe uma mais pequena.');
        $this->post('/api/feedback/capturas', [], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_captura_usa_o_disco_configurado(): void
    {
        Storage::fake('capturas');
        config(['ads-proxy.feedback.disco' => 'capturas']);

        $id = $this->post('/api/feedback/capturas', ['imagem' => UploadedFile::fake()->image('a.webp')], ['Accept' => 'application/json'])->json('captura');

        Storage::disk('capturas')->assertExists('feedback/capturas/'.$id);
        Storage::disk('public')->assertMissing('feedback/capturas/'.$id);
    }

    public function test_a_central_recusa_e_o_corpo_passa_em_422_e_429(): void
    {
        $this->centralResponde(Http::response(['estado' => 'erro', 'texto' => 'Escreve pelo menos 5 caracteres.'], 422));
        $this->postJson('/api/feedback', $this->corpo(['texto' => 'a']))->assertStatus(422)->assertJsonPath('texto', 'Escreve pelo menos 5 caracteres.');

        $this->centralResponde(Http::response(['estado' => 'erro', 'texto' => 'Muitos pedidos.'], 429));
        $this->postJson('/api/feedback', $this->corpo())->assertStatus(429)->assertJsonPath('texto', 'Muitos pedidos.');
    }

    public function test_se_a_central_falha_a_pessoa_fica_a_saber_e_fica_um_aviso(): void
    {
        $texto = 'Não foi possível enviar o teu comentário. Tenta de novo.';
        Log::shouldReceive('warning')->times(3)->with('hongayetu.feedback.falhou', \Mockery::type('array'));

        $this->centralResponde(Http::response(['estado' => 'erro'], 500));
        $this->postJson('/api/feedback', $this->corpo())->assertStatus(503)->assertExactJson(['estado' => 'erro', 'texto' => $texto]);

        // O token do projecto recusado não é a sessão da pessoa: nunca chega como 401.
        $this->centralResponde(Http::response(['message' => 'Unauthenticated.'], 401));
        $this->postJson('/api/feedback', $this->corpo())->assertStatus(503)->assertJsonPath('texto', $texto);

        $this->centralResponde(fn () => throw new ConnectionException('tempo esgotado'));
        $this->postJson('/api/feedback', $this->corpo())->assertStatus(503)->assertJsonPath('texto', $texto);
    }

    public function test_sem_token_responde_503_com_aviso(): void
    {
        config(['ads-proxy.token' => null]);
        $this->app->forgetInstance(\HongaYetu\InternalAdsProxy\Http\FeedbackClient::class);
        Log::shouldReceive('warning')->once();

        $this->postJson('/api/feedback', $this->corpo())->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_o_kill_switch_responde_503_sem_chamar_a_central(): void
    {
        config(['ads-proxy.feedback.enabled' => false]);

        $this->postJson('/api/feedback', $this->corpo())->assertStatus(503)->assertJsonPath('estado', 'erro');
        $this->post('/api/feedback/capturas', ['imagem' => UploadedFile::fake()->image('a.png')], ['Accept' => 'application/json'])->assertStatus(503);

        Http::assertNothingSent();
    }

    public function test_o_token_proprio_do_feedback_ganha_ao_das_mensagens(): void
    {
        config(['ads-proxy.mensagens.token' => 'das-mensagens', 'ads-proxy.feedback.token' => 'do-feedback']);
        $this->app->forgetInstance(\HongaYetu\InternalAdsProxy\Http\FeedbackClient::class);

        $this->postJson('/api/feedback', $this->corpo())->assertOk();

        $this->assertTrue($this->enviado()->hasHeader('Authorization', 'Bearer do-feedback'));
    }

    public function test_na_web_funciona_com_a_sessao_do_guard_web(): void
    {
        $this->actingAs(new GenericUser(['id' => 9, 'name' => 'Rui']), 'web');
        config(['ads-proxy.feedback.web.guard' => 'web']);

        $this->post('/feedback', $this->corpo(), ['Accept' => 'application/json'])->assertOk();

        $this->assertSame('9', $this->enviado()->data()['conta_externa_id']);
    }
}

/** Com o auto-registo desligado por env, as rotas do feedback não existem. */
class FeedbackAutoRegistoDesligadoTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AdsProxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('ads-proxy.feedback.auto_register_routes', false);
        $app['config']->set('ads-proxy.feedback.web.auto_register_routes', false);
    }

    public function test_as_rotas_nao_se_registam(): void
    {
        foreach (['feedback.enviar', 'feedback.capturas', 'feedback.web.enviar', 'feedback.web.capturas'] as $nome) {
            $this->assertNull(Route::getRoutes()->getByName($nome), $nome);
        }
    }
}

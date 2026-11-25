<?php

namespace HongaYetu\InternalAdsProxy\Http\Controllers;

use HongaYetu\InternalAdsProxy\Http\MensagensPopupClient;
use HongaYetu\InternalAdsProxy\MensagensPopupWeb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Proxy das mensagens em popup da central Honga Yetu.
 *
 * A app ou o site do serviço pede as mensagens a este servidor, com a sessão
 * que já tem cá; este servidor vai buscá-las à central com o token do
 * ConnectedProject e diz de que conta Honga Yetu é o pedido.
 *
 * Uma regra acima de todas: **as mensagens nunca partem a entrada da app.**
 * Desligado, sem conta Honga Yetu, com a central em baixo ou lenta, a lista
 * responde vazia e o evento responde «ok». Só as recusas da central a um
 * evento (4xx) chegam à app, porque essas dizem-lhe alguma coisa.
 */
class MensagensPopupController extends Controller
{
    /** O que um evento pode levar à central, além da conta. */
    protected const CAMPOS_DO_EVENTO = [
        'tipo', 'servico', 'superficie', 'versao_app', 'plataforma', 'botao',
        'destino', 'segundos', 'contexto_tipo', 'contexto_id', 'contexto_nome',
    ];

    public function __construct(protected MensagensPopupClient $client) {}

    public function index(Request $request): JsonResponse
    {
        if ($this->desligado()) {
            return $this->listaVazia();
        }

        $hongaUserId = $this->hongaUserId($request);
        $servico = $this->servico($request->query('servico'));

        if ($hongaUserId === null || $servico === null || ! $this->servicoPermitido($servico)) {
            return $this->listaVazia();
        }

        /*
         * O `honga_user_id` sai sempre da sessão deste servidor. O que vier no
         * pedido não entra aqui: de outro modo qualquer pessoa lia (e marcava
         * como vistas) as mensagens de outra conta. O `empresa_id` e o
         * `escola_id` da API pública da central também ficam de fora — este
         * token fala por um serviço, não por uma empresa.
         */
        $resposta = $this->client->listar(array_filter([
            'honga_user_id' => $hongaUserId,
            'servico' => $servico,
            'superficie' => $this->texto($request->query('superficie')),
            'versao_app' => $this->texto($request->query('versao_app')),
            'plataforma' => $this->texto($request->query('plataforma')),
        ], fn ($valor) => $valor !== null));

        if ($resposta === null) {
            return $this->listaVazia();
        }

        $payload = $resposta->json();

        if (! $resposta->successful() || ! is_array($payload) || ! is_array($payload['mensagens'] ?? null)) {
            $this->logger()->warning('hongayetu.mensagens_popup.lista_recusada', [
                'estado_upstream' => $resposta->status(),
                'servico' => $servico,
            ]);

            return $this->listaVazia();
        }

        return response()->json($payload);
    }

    public function evento(Request $request, string $mensagem): JsonResponse
    {
        if ($this->desligado()) {
            return $this->eventoAceite();
        }

        $hongaUserId = $this->hongaUserId($request);
        $servico = $this->servico($request->input('servico'));

        if ($hongaUserId === null || ($servico !== null && ! $this->servicoPermitido($servico))) {
            return $this->eventoAceite();
        }

        $dados = array_filter(
            $request->only(self::CAMPOS_DO_EVENTO),
            fn ($valor) => $valor !== null,
        );

        if ($servico !== null) {
            $dados['servico'] = $servico;
        }

        // Por último, para que nada do pedido lhe passe por cima.
        $dados['honga_user_id'] = $hongaUserId;

        $resposta = $this->client->registarEvento($mensagem, $dados);

        if ($resposta === null) {
            return $this->eventoAceite();
        }

        if ($resposta->successful()) {
            return response()->json($resposta->json() ?? ['estado' => 'ok'], $resposta->status());
        }

        /*
         * Um 401 da central é o token deste serviço a ser recusado, não a
         * sessão da pessoa. Passá-lo à app dizia-lhe que a sessão dela acabou,
         * e muitas terminam a sessão ao ver um 401. Fica aqui, com aviso.
         */
        if ($resposta->clientError() && $resposta->status() !== 401) {
            return response()->json($resposta->json() ?? [
                'estado' => 'erro',
                'texto' => 'Não foi possível registar a tua acção. Tenta de novo.',
            ], $resposta->status());
        }

        $this->logger()->warning('hongayetu.mensagens_popup.evento_falhou', [
            'estado_upstream' => $resposta->status(),
            'mensagem' => $mensagem,
        ]);

        return $this->eventoAceite();
    }

    /**
     * A lista pedida pelo site (sessão web, o `popup.js`).
     *
     * A conta continua a sair da sessão. O `servico` e a `superficie` vêm do
     * pedido, mas só valem se forem os de uma zona configurada em
     * `mensagens.web.zonas`: de outro modo a lista vem vazia sem se chamar a
     * central. A superfície `app` nunca é uma zona da web.
     */
    public function indexWeb(Request $request): JsonResponse
    {
        if (! MensagensPopupWeb::ligada() || $this->zonaDoPedido($request, $request->query('servico'), $request->query('superficie')) === null) {
            return $this->listaVazia();
        }

        return $this->index($request);
    }

    /** Um evento mandado pelo site. Fora de uma zona configurada, responde «ok» sem chamar a central. */
    public function eventoWeb(Request $request, string $mensagem): JsonResponse
    {
        if (! MensagensPopupWeb::ligada() || $this->zonaDoPedido($request, $request->input('servico'), $request->input('superficie')) === null) {
            return $this->eventoAceite();
        }

        return $this->evento($request, $mensagem);
    }

    /**
     * O `popup.js`: JavaScript puro, igual para toda a gente, sem nada da
     * pessoa. Por isso não leva sessão e pode ficar em cache; o `?v=` que a
     * directiva lhe põe muda quando o ficheiro muda.
     */
    public function script(Request $request): Response
    {
        $caminho = MensagensPopupWeb::caminhoDoScript();

        abort_unless(is_file($caminho), 404);

        $resposta = new Response((string) file_get_contents($caminho), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $resposta->setPublic();
        $resposta->setMaxAge(3600);
        $resposta->setEtag(MensagensPopupWeb::versaoDoScript());
        $resposta->isNotModified($request);

        return $resposta;
    }

    /** @return array<string, mixed>|null */
    protected function zonaDoPedido(Request $request, mixed $servico, mixed $superficie): ?array
    {
        return MensagensPopupWeb::zona($this->texto($servico), $this->texto($superficie), $request->getHost());
    }

    /**
     * A conta Honga Yetu de quem está autenticado neste serviço, ou `null`.
     *
     * O nome do atributo é configurável porque nem todos os serviços lhe
     * chamam `honga_user_id`.
     */
    protected function hongaUserId(Request $request): ?int
    {
        $utilizador = $request->user();

        if ($utilizador === null) {
            return null;
        }

        $atributo = (string) $this->config('atributo_do_utilizador', 'honga_user_id');
        $valor = data_get($utilizador, $atributo);

        return is_numeric($valor) && (int) $valor > 0 ? (int) $valor : null;
    }

    /** O serviço do pedido ou, se não vier, o que está configurado por omissão. */
    protected function servico(mixed $doPedido): ?string
    {
        return $this->texto($doPedido) ?? $this->texto($this->config('servico_por_omissao'));
    }

    protected function servicoPermitido(string $servico): bool
    {
        $permitidos = array_filter((array) $this->config('servicos_permitidos', []));

        return $permitidos === [] || in_array($servico, $permitidos, true);
    }

    protected function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    protected function desligado(): bool
    {
        return ! (bool) $this->config('enabled', true);
    }

    protected function listaVazia(): JsonResponse
    {
        return response()->json(['estado' => 'ok', 'mensagens' => []]);
    }

    protected function eventoAceite(): JsonResponse
    {
        return response()->json(['estado' => 'ok']);
    }

    protected function config(string $chave, mixed $omissao = null): mixed
    {
        return config('ads-proxy.mensagens.'.$chave, $omissao);
    }

    /** O mesmo canal dos anúncios, para os avisos ficarem todos no mesmo sítio. */
    protected function logger()
    {
        $canal = config('ads-proxy.log_channel');

        return $canal ? Log::channel($canal) : Log::getFacadeRoot();
    }
}

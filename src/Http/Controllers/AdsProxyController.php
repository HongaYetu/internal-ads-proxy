<?php

namespace HongaYetu\InternalAdsProxy\Http\Controllers;

use HongaYetu\InternalAdsProxy\Http\AdsProxyClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class AdsProxyController extends Controller
{
    public function __construct(protected AdsProxyClient $client) {}

    public function serve(Request $request): JsonResponse
    {
        if ($this->desligado()) {
            return $this->respostaDesligado();
        }

        $dados = $request->validate([
            'espaco_slug' => 'required|string|max:64',
            'formato_id' => 'nullable|integer',
            'sublocal' => 'nullable|string',
            'slot_width' => 'nullable|integer|min:1|max:10000',
            'slot_height' => 'nullable|integer|min:1|max:10000',
            'device_id' => 'nullable|string|max:128',
            'user_age' => 'nullable|integer|min:0|max:120',
            'geo_country' => 'nullable|string|size:2',
            'formatos_aceites' => 'nullable|array|max:14',
            'formatos_aceites.*.largura' => 'required_with:formatos_aceites|integer|min:1|max:10000',
            'formatos_aceites.*.altura' => 'required_with:formatos_aceites|integer|min:1|max:10000',
            // Palavras-chave de conteúdo — o que está no ecrã. O proxy só as
            // deixa passar: quem normaliza e pontua é a API central. Sem esta
            // regra o `validate` deitava-as fora em silêncio e o targeting de
            // conteúdo não chegava a sair da app.
            'keywords' => 'nullable|array|max:20',
            'keywords.*' => 'string|max:40',
            // O consumidor sabe reproduzir vídeo. O proxy só deixa passar;
            // quem decide a proporção video/imagem é a API central.
            'suporta_video' => 'nullable|boolean',
        ]);

        // Injectar honga_user_id se o user autenticado no consumer tem perfil
        // HongaYetu ligado. Esta é a forma como o motor central faz targeting
        // demográfico (idade/género/geo) — sem expor PII ao SDK.
        if (function_exists('auth') && auth()->check()) {
            $u = auth()->user();
            $hongaUserId = $u->honga_user_id ?? null;
            if (! empty($hongaUserId)) {
                $dados['honga_user_id'] = (int) $hongaUserId;
            }
        }

        return $this->forward($this->client->serve($dados));
    }

    public function videoEvent(Request $request): JsonResponse
    {
        if ($this->desligado()) {
            return $this->respostaDesligado();
        }

        $dados = $request->validate([
            'token' => 'required|string',
            'event' => 'required|string|in:start,quartil_25,quartil_50,quartil_75,complete,skip,close',
            'device_id' => 'nullable|string',
            'position_ms' => 'nullable|integer|min:0',
        ]);

        return $this->forward($this->client->videoEvent($dados));
    }

    public function impression(Request $request): JsonResponse
    {
        if ($this->desligado()) {
            return $this->respostaDesligado();
        }

        $dados = $request->validate([
            'token' => 'required|string',
            'device_id' => 'nullable|string',
            'viewed_ms' => 'nullable|integer|min:0',
        ]);

        return $this->forward($this->client->impression($this->comCliente($request, $dados)));
    }

    public function click(Request $request): JsonResponse
    {
        if ($this->desligado()) {
            return $this->respostaDesligado();
        }

        $dados = $request->validate([
            'token' => 'required|string',
            'device_id' => 'nullable|string',
        ]);

        return $this->forward($this->client->click($this->comCliente($request, $dados)));
    }

    /**
     * Click via GET — pensado para uso directo como `href` num `<a>` no SDK web.
     * Regista server-side a impressão de clique na API central e responde com
     * 302 para o destino real do anúncio. Em caso de falha (token inválido,
     * expirado, etc) cai num fallback configurável (default: home `/`) — nunca
     * mostra erro ao utilizador final.
     */
    public function clickRedirect(Request $request, string $token): RedirectResponse
    {
        $fallback = (string) config('ads-proxy.click_fallback_url', '/');

        if ($this->desligado() || $token === '') {
            return redirect()->away($fallback);
        }

        $resposta = $this->client->click($this->comCliente($request, ['token' => $token]));
        if (! $resposta) {
            $this->avisarFallback($token, 'sem_resposta');

            return redirect()->away($fallback);
        }

        $payload = $resposta->json() ?? [];
        $url = (string) data_get($payload, 'data.redirect_url', '');

        if ($url === '' || ! $this->urlExterna($url)) {
            /*
             * Cair no fallback é mandar a pessoa para a página inicial em vez do
             * anunciante, e o clique não conta a ninguém. É um desfecho legítimo
             * — token expirado, campanha sem destino —, mas tem de deixar rasto:
             * enquanto foi silencioso, uma recusa sistemática da central (a
             * verificação de aparelho, que este proxy nunca podia satisfazer)
             * levou **todos** os cliques desta rede para a home sem uma linha de
             * log a dizê-lo.
             */
            $this->avisarFallback($token, $url === '' ? 'sem_destino' : 'destino_interno', $resposta->status());

            return redirect()->away($url !== '' ? $url : $fallback);
        }

        return redirect()->away($url);
    }

    /** O mesmo canal do cliente HTTP, para os avisos ficarem todos no mesmo sítio. */
    protected function logger()
    {
        $canal = config('ads-proxy.log_channel');

        return $canal ? Log::channel($canal) : Log::getFacadeRoot();
    }

    /** O fallback deixa rasto: um clique perdido em silêncio não se descobre. */
    protected function avisarFallback(string $token, string $motivo, ?int $estado = null): void
    {
        $this->logger()->warning('hongayetu.ads_proxy.clique_sem_destino', [
            'motivo' => $motivo,
            'estado_upstream' => $estado,
            // Só o princípio do token: chega para o cruzar com os logs da
            // central e não o deixa reutilizável a quem leia o ficheiro.
            'token' => substr($token, 0, 12).'…',
        ]);
    }

    /**
     * Acrescenta ao payload quem é o cliente real deste evento.
     *
     * Estes pedidos partem **daqui**, do servidor da app, e não do aparelho de
     * quem viu o anúncio. Sem isto, a central via sempre o mesmo IP e o mesmo
     * agente — o desta máquina — e o guarda anti-fraude dela, que conta eventos
     * por IP e por minuto, lia uma aplicação inteira como um único aparelho a
     * martelar. A partir de certo tráfego começava a descartar cliques
     * legítimos, em silêncio e sem os contabilizar a ninguém.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    protected function comCliente(Request $request, array $dados): array
    {
        return array_filter([
            ...$dados,
            'client_ip' => $request->ip(),
            'client_user_agent' => $request->userAgent(),
        ], fn ($v) => $v !== null);
    }

    /**
     * Garante que a URL final é absoluta — protege contra open-redirect a
     * paths internos do site host (e.g. token apontando para `/admin`).
     */
    protected function urlExterna(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', $url);
    }

    protected function desligado(): bool
    {
        return ! (bool) config('ads-proxy.enabled', true);
    }

    protected function respostaDesligado(): JsonResponse
    {
        return response()->json([
            'estado' => 'ok',
            'data' => null,
            'meta' => ['reason' => 'ads_disabled'],
        ], 200);
    }

    protected function forward($resposta): JsonResponse
    {
        if ($resposta === null) {
            return response()->json([
                'estado' => 'erro',
                'texto' => 'Serviço de anúncios indisponível.',
            ], 502);
        }

        $payload = $resposta->json() ?? ['estado' => 'erro', 'texto' => 'Resposta inválida.'];

        return response()->json($payload, $resposta->status());
    }
}

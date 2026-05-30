<?php

namespace HongaYetu\InternalAdsProxy\Http\Controllers;

use HongaYetu\InternalAdsProxy\Http\AdsProxyClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

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
        ]);

        return $this->forward($this->client->serve($dados));
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

        return $this->forward($this->client->impression($dados));
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

        return $this->forward($this->client->click($dados));
    }

    /**
     * Click via GET — pensado para uso directo como `href` num `<a>` no SDK web.
     * Regista server-side a impressão de clique na API central e responde com
     * 302 para o destino real do anúncio. Em caso de falha (token inválido,
     * expirado, etc) cai num fallback configurável (default: home `/`) — nunca
     * mostra erro ao utilizador final.
     */
    public function clickRedirect(string $token): RedirectResponse
    {
        $fallback = (string) config('ads-proxy.click_fallback_url', '/');

        if ($this->desligado() || $token === '') {
            return redirect()->away($fallback);
        }

        $resposta = $this->client->click(['token' => $token]);
        if (! $resposta) {
            return redirect()->away($fallback);
        }

        $payload = $resposta->json() ?? [];
        $url = (string) data_get($payload, 'data.redirect_url', '');

        if ($url === '' || ! $this->urlExterna($url)) {
            return redirect()->away($url !== '' ? $url : $fallback);
        }

        return redirect()->away($url);
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

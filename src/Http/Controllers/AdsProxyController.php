<?php

namespace HongaYetu\InternalAdsProxy\Http\Controllers;

use HongaYetu\InternalAdsProxy\Http\AdsProxyClient;
use Illuminate\Http\JsonResponse;
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
            'device_id' => 'nullable|string|max:128',
            'user_age' => 'nullable|integer|min:0|max:120',
            'geo_country' => 'nullable|string|size:2',
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

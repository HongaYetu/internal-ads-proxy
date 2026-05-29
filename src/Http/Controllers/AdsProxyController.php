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
        $dados = $request->validate([
            'espaco_id' => 'required|integer',
            'formato_id' => 'nullable|integer',
            'origem' => 'nullable|string',
            'sublocal' => 'nullable|string',
            'device_id' => 'nullable|string|max:128',
            'user_age' => 'nullable|integer|min:0|max:120',
            'geo_country' => 'nullable|string|size:2',
        ]);

        return $this->forward($this->client->serve($dados));
    }

    public function impression(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'token' => 'required|string',
            'device_id' => 'nullable|string',
            'viewed_ms' => 'nullable|integer|min:0',
        ]);

        return $this->forward($this->client->impression($dados));
    }

    public function click(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'token' => 'required|string',
            'device_id' => 'nullable|string',
        ]);

        return $this->forward($this->client->click($dados));
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

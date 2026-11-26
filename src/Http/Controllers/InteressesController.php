<?php

namespace HongaYetu\InternalAdsProxy\Http\Controllers;

use HongaYetu\InternalAdsProxy\Http\InteressesClient;
use HongaYetu\InternalAdsProxy\Identidade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Os sinais de interesse (`pesquisa`, `ver_item`, `compra`, `ver_ecra`) que a app ou o site do serviço manda
 * para o perfil de temas da conta Honga Yetu de quem está autenticado.
 *
 * É fire-and-forget: tirando um corpo inválido (422), a resposta é sempre `200 {"estado":"ok", …}`. Sem
 * sessão, sem conta Honga Yetu ligada, com a função desligada ou com a central em falha, vem `registados: 0`
 * — nunca um erro que parta o ecrã do cliente.
 *
 * A conta sai sempre do utilizador autenticado neste servidor. Um `honga_user_id` no corpo é ignorado.
 */
class InteressesController extends Controller
{
    public const TIPOS = ['compra', 'pesquisa', 'ver_item', 'ver_ecra'];

    public function __construct(protected InteressesClient $client) {}

    public function registar(Request $request): JsonResponse
    {
        $validador = Validator::make($request->only(['tipo', 'temas']), [
            'tipo' => 'required|string|in:'.implode(',', self::TIPOS),
            'temas' => 'required|array|min:1|max:10',
            'temas.*' => 'required|string|min:1|max:60',
        ]);

        if ($validador->fails()) {
            return response()->json(['estado' => 'erro', 'texto' => $validador->errors()->first()], 422);
        }

        if (! (bool) config('ads-proxy.interesses.enabled', true)) {
            return $this->nada();
        }

        $hongaUserId = Identidade::hongaUserId($this->utilizador($request));

        if ($hongaUserId === null) {
            return $this->nada();
        }

        $dados = $validador->validated();

        $resposta = $this->client->registar([
            'honga_user_id' => $hongaUserId,
            'tipo' => $dados['tipo'],
            'temas' => array_values($dados['temas']),
        ]);

        $corpo = $resposta?->successful() ? $resposta->json() : null;

        if (! is_array($corpo) || ($corpo['estado'] ?? null) !== 'ok') {
            $this->logger()->warning('hongayetu.interesses.central_falhou', [
                'estado_upstream' => $resposta?->status(),
            ]);

            return $this->nada();
        }

        return response()->json(array_filter([
            'estado' => 'ok',
            'registados' => (int) ($corpo['registados'] ?? 0),
            'ignorados' => isset($corpo['ignorados']) ? (int) $corpo['ignorados'] : null,
            'motivo' => isset($corpo['motivo']) && is_string($corpo['motivo']) ? $corpo['motivo'] : null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Quem está autenticado. A rota não leva `auth` (um visitante recebe 200, não 401), por isso procura-se
     * nos guards: o de `interesses.guard` primeiro e depois os de todo o package (`Identidade::quemPede`).
     */
    protected function utilizador(Request $request): mixed
    {
        $configurado = config('ads-proxy.interesses.guard');

        return Identidade::quemPede($request, is_string($configurado) ? $configurado : null);
    }

    protected function nada(): JsonResponse
    {
        return response()->json(['estado' => 'ok', 'registados' => 0]);
    }

    protected function logger()
    {
        $canal = config('ads-proxy.log_channel');

        return $canal ? Log::channel($canal) : Log::getFacadeRoot();
    }
}

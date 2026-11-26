<?php

namespace HongaYetu\InternalAdsProxy\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP fino para os interesses da central Honga Yetu (`POST /api/v1/servicos/interesses`), servidor a
 * servidor, com o token do ConnectedProject. Devolve a `Response` tal como vem, ou `null` quando não chegou a
 * haver resposta (mal configurado, sem rede, tempo esgotado). O tempo de espera é curto de propósito: o sinal
 * é fire-and-forget e nunca pode segurar o ecrã de quem o mandou.
 */
class InteressesClient
{
    public function __construct(
        protected string $baseUrl,
        protected ?string $token,
        protected int $timeoutSeconds = 3,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->baseUrl) && ! empty($this->token);
    }

    /**
     * @param  array{honga_user_id: int, tipo: string, temas: list<string>}  $dados
     */
    public function registar(array $dados): ?Response
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            return Http::withToken($this->token)
                ->acceptJson()
                ->connectTimeout($this->timeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->asJson()
                ->post($this->baseUrl, $dados);
        } catch (\Throwable) {
            return null;
        }
    }
}

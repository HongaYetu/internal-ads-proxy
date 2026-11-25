<?php

namespace HongaYetu\InternalAdsProxy\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP fino para o feedback da central Honga Yetu (`POST /api/v1/servicos/feedback`), servidor a
 * servidor, com o token do ConnectedProject. Devolve a `Response` tal como vem, ou `null` quando não chegou
 * a haver resposta (mal configurado, sem rede, tempo esgotado): quem decide o que a pessoa lê é o controller.
 */
class FeedbackClient
{
    public function __construct(
        protected string $baseUrl,
        protected ?string $token,
        protected int $timeoutSeconds = 8,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->baseUrl) && ! empty($this->token);
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    public function enviar(array $dados): ?Response
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            return Http::withToken($this->token)
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->asJson()
                ->post($this->baseUrl, $dados);
        } catch (\Throwable) {
            return null;
        }
    }
}

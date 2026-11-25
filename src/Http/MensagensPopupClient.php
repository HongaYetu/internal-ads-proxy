<?php

namespace HongaYetu\InternalAdsProxy\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP fino para as mensagens em popup da central Honga Yetu.
 *
 * Fala com a central em nome do serviço (o token do ConnectedProject) e diz-lhe
 * de que conta Honga Yetu é o pedido. Devolve a `Response` tal como vem, ou
 * `null` quando não chegou a haver resposta (mal configurado, sem rede, tempo
 * esgotado): quem decide o que a app recebe é o controller.
 */
class MensagensPopupClient
{
    public function __construct(
        protected string $baseUrl,
        protected ?string $token,
        protected int $timeoutSeconds = 5,
        protected ?string $logChannel = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * As mensagens por mostrar a uma conta, num serviço e numa superfície.
     *
     * @param  array<string, mixed>  $query
     */
    public function listar(array $query): ?Response
    {
        return $this->pedir('get', '', $query);
    }

    /**
     * Regista um evento (vista, fechada, clique…) de uma mensagem.
     *
     * @param  array<string, mixed>  $dados
     */
    public function registarEvento(int|string $mensagem, array $dados): ?Response
    {
        return $this->pedir('post', '/'.$mensagem.'/eventos', $dados);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->baseUrl) && ! empty($this->token);
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    protected function pedir(string $metodo, string $caminho, array $dados): ?Response
    {
        if (! $this->isConfigured()) {
            $this->logger()->warning('hongayetu.mensagens_popup.mal_configurado', [
                'caminho' => $caminho,
                'tem_base_url' => ! empty($this->baseUrl),
                'tem_token' => ! empty($this->token),
            ]);

            return null;
        }

        try {
            return $metodo === 'get'
                ? $this->http()->get($this->baseUrl.$caminho, $dados)
                : $this->http()->asJson()->post($this->baseUrl.$caminho, $dados);
        } catch (ConnectionException $e) {
            $this->logger()->warning('hongayetu.mensagens_popup.timeout', [
                'caminho' => $caminho,
                'erro' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            $this->logger()->warning('hongayetu.mensagens_popup.falhou', [
                'caminho' => $caminho,
                'erro' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function http(): PendingRequest
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->timeout($this->timeoutSeconds);
    }

    protected function logger()
    {
        return $this->logChannel ? Log::channel($this->logChannel) : Log::getFacadeRoot();
    }
}

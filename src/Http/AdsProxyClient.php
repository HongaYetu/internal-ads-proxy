<?php

namespace HongaYetu\InternalAdsProxy\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP fino para a API v2 de anúncios. Encapsula a configuração
 * (baseUrl, token, timeout) e devolve a `Response` Laravel tal como vem para
 * o controller propagar o status code original.
 */
class AdsProxyClient
{
    public function __construct(
        protected string $baseUrl,
        protected ?string $token,
        protected int $timeoutSeconds = 8,
        protected ?string $logChannel = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function serve(array $payload): ?Response
    {
        return $this->post('/serve', $payload);
    }

    public function impression(array $payload): ?Response
    {
        return $this->post('/impression', $payload);
    }

    public function click(array $payload): ?Response
    {
        return $this->post('/click', $payload);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->baseUrl) && ! empty($this->token);
    }

    protected function post(string $path, array $payload): ?Response
    {
        if (! $this->isConfigured()) {
            $this->logger()->warning('hongayetu.ads_proxy.mal_configurado', [
                'path' => $path,
                'tem_base_url' => ! empty($this->baseUrl),
                'tem_token' => ! empty($this->token),
            ]);

            return null;
        }

        try {
            return $this->http()->post($this->baseUrl.$path, $payload);
        } catch (ConnectionException $e) {
            $this->logger()->warning('hongayetu.ads_proxy.timeout', [
                'path' => $path,
                'erro' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            $this->logger()->warning('hongayetu.ads_proxy.falhou', [
                'path' => $path,
                'erro' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function http(): PendingRequest
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds);
    }

    protected function logger()
    {
        return $this->logChannel ? Log::channel($this->logChannel) : Log::getFacadeRoot();
    }
}

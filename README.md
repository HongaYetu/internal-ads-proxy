# hongayetu/internal-ads-proxy

Composer package que **encapsula o proxy Laravel para a API v2 de anúncios da HongaYetu**. Mantém o bearer token do `ConnectedProject` no servidor — fora do bundle das apps mobile que consomem a [`@hongayetu/internal-ads-react-native`](https://github.com/HongaYetu/internal-ads-react-native) SDK.

> ⚠️ Package interno do ecossistema HongaYetu. Versões seguem o ritmo da central. Não publicado em packagist público.

## Porquê

A API v2 da HongaYetu requer um bearer token (`Bearer ...`) em cada chamada. Se a app mobile o usar directamente, o token fica embutido no APK/IPA e pode ser extraído por reverse engineering, levando a fraude de impressões/cliques.

O padrão recomendado: **a app mobile fala com o teu próprio backend (autenticada como o utilizador real), e o teu backend faz proxy para a HongaYetu com o token server-side**. Este package implementa esse proxy em ~50 LOC mais 3 rotas — basta `composer require` + 2 vars no `.env`.

```
[App mobile autenticada como user]
        │  Bearer = sessão Sanctum/JWT do user na tua app
        ▼
[Teu backend Laravel + este package]
        │  Bearer = ConnectedProject token (server-side, .env)
        ▼
[anuncios.hongayetu.com/api/v2/ads/*]
```

## Instalação

```bash
composer require hongayetu/internal-ads-proxy
```

Laravel auto-descobre o `ServiceProvider`. Não precisas registar nada à mão.

Define no `.env`:
```dotenv
HONGAYETU_ADS_BASE_URL=https://anuncios.hongayetu.com/api/v2/ads
HONGAYETU_ADS_TOKEN=<bearer-token-emitido-pelo-Filament>
```

Pronto. As rotas ficam disponíveis em:
- `POST /api/ads-proxy/serve`
- `POST /api/ads-proxy/impression`
- `POST /api/ads-proxy/click`

Todas com middleware `auth:sanctum` + `throttle:60,1` por defeito.

## Configuração do SDK no app mobile

```tsx
import { AdsProvider, AdView } from '@hongayetu/internal-ads-react-native';

<AdsProvider
  config={{
    baseUrl: 'https://api.humbi.com/api/ads-proxy', // ← teu backend
    token: utilizador.sanctumToken,                  // ← sessão da tua app
    mode: 'proxy',
  }}
>
  <AdView espacoId={1} origem="humbi_shop" sublocal="feed" />
</AdsProvider>
```

## Customização

### Alterar prefix ou middleware

Publica o config:
```bash
php artisan vendor:publish --tag=hongayetu-ads-proxy-config
```

Edita `config/ads-proxy.php`:
```php
return [
    'route_prefix' => 'api/v1/anuncios',  // muda o URL público
    'middleware' => ['auth:api', 'throttle:120,1'],
    // ...
];
```

### Desactivar auto-registo e gerir manualmente

```dotenv
HONGAYETU_ADS_PROXY_AUTO_ROUTES=false
```

Depois em `routes/api.php`:
```php
use HongaYetu\InternalAdsProxy\Http\Controllers\AdsProxyController;

Route::middleware(['auth:sanctum'])->prefix('v2/ads')->group(function () {
    Route::post('/serve', [AdsProxyController::class, 'serve']);
    Route::post('/impression', [AdsProxyController::class, 'impression']);
    Route::post('/click', [AdsProxyController::class, 'click']);
});
```

### Usar o cliente HTTP directamente (server-side serving)

Para casos onde queres servir um anúncio do teu backend (ex: render server-side em Inertia/Blade), não precisas das rotas — usa o cliente:

```php
use HongaYetu\InternalAdsProxy\Http\AdsProxyClient;

public function show(AdsProxyClient $client)
{
    $resposta = $client->serve([
        'espaco_id' => 1,
        'origem' => 'humbi_web',
        'sublocal' => 'home',
    ]);
    $dados = $resposta?->json('data');

    return view('home', ['anuncio' => $dados]);
}
```

## Variáveis de ambiente

| Variável | Default | Descrição |
|---|---|---|
| `HONGAYETU_ADS_BASE_URL` | `https://anuncios.hongayetu.com/api/v2/ads` | URL base da API v2 (sem barra final). |
| `HONGAYETU_ADS_TOKEN` | — | **Obrigatório**. Bearer token do ConnectedProject. |
| `HONGAYETU_ADS_TIMEOUT` | `8` | Timeout HTTP em segundos. |
| `HONGAYETU_ADS_PROXY_AUTO_ROUTES` | `true` | Auto-registar rotas no boot. |
| `HONGAYETU_ADS_PROXY_PREFIX` | `api/ads-proxy` | Prefix das rotas auto-registadas. |
| `HONGAYETU_ADS_LOG_CHANNEL` | (default) | Canal Laravel para warnings de falhas upstream. |

## Comportamento

- **Sem cache**: cada `/serve` é sempre encaminhado. A central já tem cache curto onde faz sentido.
- **Status code propagado**: 401/422/429 da central são reenviados tal-qual para o SDK lidar (ex: 422 "Token replay" devolvido ao app).
- **Timeout / falha de rede**: devolve `502 Serviço de anúncios indisponível` + log warning. O SDK degrada gracefully (não renderiza anúncio).
- **Sem retry**: o SDK é stateless — se /serve falhar, próxima visualização tenta de novo. Não tentamos disfarçar problemas upstream.

## Defesas na central (já existentes — não duplicar aqui)

- HMAC + nonce one-shot em tokens de impressão/clique.
- Device hash matching.
- Frequency cap por (device, anúncio, dia).
- Rate limit por ConnectedProject (120/min serve, 600/min track).
- **Circuit breaker de orçamento diário** — configurável no Filament, hard-stop quando atingido.
- Anomaly detection de devices novos por minuto.

Este proxy elimina o último vector ofensivo: o token estático no bundle.

## Desenvolvimento

```bash
composer install
vendor/bin/phpunit
```

## Licença

Proprietary. Uso restrito ao ecossistema HongaYetu.

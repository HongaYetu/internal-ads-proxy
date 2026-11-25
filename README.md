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

Todas com middleware `auth:sanctum` + `throttle:60,1,anuncios` por defeito.

> O limite tem de ter **nome** (o 3.º argumento, `anuncios`). Um `throttle:N,M` sem nome usa a mesma chave — o utilizador, ou o domínio e o IP — em todas as rotas da app, seja qual for o N: os pedidos dos anúncios de um ecrã gastam o limite das outras rotas. No DesAppego esgotavam o limite do login e a revisão do Google recusou a app. Se mudares o middleware, mantém um nome próprio no `throttle`.

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
    'middleware' => ['auth:api', 'throttle:120,1,anuncios'], // throttle sempre com nome
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

Route::middleware(['auth:sanctum', 'throttle:60,1,anuncios'])->prefix('v2/ads')->group(function () {
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
| `HONGAYETU_ADS_ENABLED` | `true` | **Kill switch.** Quando `false`, todos os endpoints devolvem `data:null` sem chamar a central. Pausa imediata sem deploy nem revogar tokens. |
| `HONGAYETU_ADS_BASE_URL` | `https://anuncios.hongayetu.com/api/v2/ads` | URL base da API v2 (sem barra final). |
| `HONGAYETU_ADS_TOKEN` | — | **Obrigatório**. Bearer token do ConnectedProject. |
| `HONGAYETU_ADS_TIMEOUT` | `8` | Timeout HTTP em segundos. |
| `HONGAYETU_ADS_PROXY_AUTO_ROUTES` | `true` | Auto-registar rotas no boot. |
| `HONGAYETU_ADS_PROXY_PREFIX` | `api/ads-proxy` | Prefix das rotas auto-registadas. |
| `HONGAYETU_ADS_LOG_CHANNEL` | (default) | Canal Laravel para warnings de falhas upstream. |

## Mensagens em popup (desde a 0.10.0)

O package faz também de proxy das **mensagens em popup** da central Honga Yetu. A app ou o site pede-as ao servidor do próprio serviço, com a sessão que já tem, e este vai buscá-las à central com o token do ConnectedProject, em nome da conta Honga Yetu da pessoa.

| Rota no serviço | O que faz |
|---|---|
| `GET api/mensagens-popup?servico=…&superficie=app` | As mensagens por mostrar. Aceita também `versao_app` e `plataforma`. Responde `{"estado":"ok","mensagens":[...]}`. |
| `POST api/mensagens-popup/{mensagem}/eventos` | Regista `tipo` (`vista`, `fechada`, `dispensada`, `clique`, `video_progresso`, `video_fim`) e os opcionais `servico`, `superficie`, `versao_app`, `plataforma`, `botao`, `destino`, `segundos`, `contexto_tipo`, `contexto_id`, `contexto_nome`. Responde `{"estado":"ok"}`. |

São os caminhos e a forma da API pública da central: a app só muda o endereço base.

- **A conta vem da sessão**, nunca do pedido: `auth()->user()->honga_user_id`. Se o serviço lhe chama outra coisa, muda `mensagens.atributo_do_utilizador` (ou `HONGAYETU_MENSAGENS_ATRIBUTO`).
- **Nunca parte a entrada da app.** Desligado, sem conta Honga Yetu, com a central em baixo ou lenta: a lista vem vazia (200) e o evento vem «ok», com um aviso no log. Só as recusas da central a um evento (4xx, menos o 401) passam para a app.
- **`servicos_permitidos`**: vazia, aceita o `servico` que a app mandar; preenchida, um `servico` fora dela recebe a lista vazia sem se chamar a central.
- **`servico_por_omissao`** (`HONGAYETU_MENSAGENS_SERVICO`): usado quando o pedido não traz `servico`.
- **Sem cache.**

Para a maior parte dos serviços basta actualizar o package: o token é o dos anúncios e as rotas registam-se sozinhas com `auth:sanctum` e `throttle:60,1,mensagens-popup`. Quem publicou o `config/ads-proxy.php` copia o bloco `mensagens` do config do package para o poder ajustar.

| Variável | Default | Descrição |
|---|---|---|
| `HONGAYETU_MENSAGENS_ENABLED` | `true` | Kill switch das mensagens. Com `false`, a lista vem vazia e os eventos vêm «ok», sem chamar a central. |
| `HONGAYETU_MENSAGENS_BASE_URL` | `https://hongayetu.com/api/v1/servicos/mensagens-popup` | Endereço base na central (sem barra final). |
| `HONGAYETU_MENSAGENS_TOKEN` | o `HONGAYETU_ADS_TOKEN` | Token do ConnectedProject, se for outro que não o dos anúncios. |
| `HONGAYETU_MENSAGENS_TIMEOUT` | `5` | Tempo máximo de espera pela central, em segundos. |
| `HONGAYETU_MENSAGENS_AUTO_ROUTES` | `true` | Registar as rotas sozinho. |
| `HONGAYETU_MENSAGENS_PREFIX` | `api/mensagens-popup` | Prefixo das rotas. |
| `HONGAYETU_MENSAGENS_ATRIBUTO` | `honga_user_id` | Atributo do utilizador que guarda o id da conta Honga Yetu. |
| `HONGAYETU_MENSAGENS_SERVICO` | — | Serviço usado quando o pedido não traz `servico`. |

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

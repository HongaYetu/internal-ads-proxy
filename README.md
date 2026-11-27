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

### Anúncios por peças (desde a 0.13.0)

O `POST /serve` deixa passar o campo `aceita_nativo` (boolean) para a central. Manda-o o cliente que sabe desenhar o anúncio com os seus próprios componentes: com ele, a central pode servir um anúncio que só tem peças (título, descrição, botão, fotografia sem texto, logótipo, cor da marca), mesmo sem asset pronto no formato pedido. A resposta traz essas peças na chave `nativo` de cada anúncio (`null` nos anúncios antigos) e o proxy devolve-a tal e qual. A impressão e o clique não mudam.

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
| `HONGAYETU_ADS_HOST_HEADER` | — | O `Host` a mandar em vez do do endereço (desde a 0.14.0). Só com um `HONGAYETU_ADS_BASE_URL` em `http://` (ver abaixo). |
| `HONGAYETU_ADS_TOKEN` | — | **Obrigatório**. Bearer token do ConnectedProject. |
| `HONGAYETU_ADS_TIMEOUT` | `8` | Timeout HTTP em segundos. |
| `HONGAYETU_ADS_PROXY_AUTO_ROUTES` | `true` | Auto-registar rotas no boot. |
| `HONGAYETU_ADS_PROXY_PREFIX` | `api/ads-proxy` | Prefix das rotas auto-registadas. |
| `HONGAYETU_ADS_LOG_CHANNEL` | (default) | Canal Laravel para warnings de falhas upstream. |

### Na mesma máquina da central: sem passar pela Cloudflare (desde a 0.14.0)

Um serviço que corre no mesmo servidor da central não precisa de dar a volta pela Cloudflare para chegar a ela: pode falar com o nginx local pelo IP, desde que mande o `Host` do site da central. No `.env` (`.env.server` em produção):

```dotenv
HONGAYETU_ADS_BASE_URL=http://127.0.0.1/api/v2/ads
HONGAYETU_ADS_HOST_HEADER=anuncios.hongayetu.com
```

- **O Host é obrigatório.** O nginx da produção tem um servidor por omissão que recusa (444) os pedidos com um Host que não conhece, e é o Host que escolhe o site da central.
- **Só com `http://`.** Em HTTPS, o certificado e o SNI são os do endereço (`127.0.0.1`), e um Host diferente dele falha. Com o endereço em `https://anuncios.hongayetu.com/…`, deixa o `HONGAYETU_ADS_HOST_HEADER` vazio.
- Vale para o `serve`, a impressão, o clique (POST e o `GET …/click/{token}`) e os eventos de vídeo. Os interesses, as mensagens em popup e o feedback vão para outros endereços (`HONGAYETU_INTERESSES_BASE_URL`, …) e não usam este Host.
- Para provar no servidor: `curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: anuncios.hongayetu.com' -X POST http://127.0.0.1/api/v2/ads/serve` dá um código da central (`401`, por ir sem token); sem o `-H`, o nginx fecha a ligação sem resposta (`000`).

### Robôs no clique (desde a 0.14.0)

O `GET {prefixo}/click/{token}` (nas rotas da API e nas dos sites) responde **404, sem chamar a central**, a um agente de robô: crawlers, browsers sem cabeça, `curl`, `wget`, `python-requests`… A central já não lhes conta o clique, mas cada ligação seguida custava-lhe um pedido. Os padrões estão em `ads-proxy.robos` e são os da central (`ads.publisher.bots_ua` no monólito): quando mudarem lá, mudam aqui. Um agente vazio não é um robô.

Põe também as rotas do proxy no `robots.txt` do site, para os robôs bem-educados nem lá irem:

```
Disallow: /api/ads-proxy/
Disallow: /anuncios-proxy/
```

## Quem pede: a conta Honga Yetu (desde a 0.13.0)

A central faz o targeting (demografia e interesses) pela conta Honga Yetu de quem vê o anúncio. Essa conta sai **sempre** de quem está autenticado neste servidor, nunca do corpo do pedido, e segue como `honga_user_id` no `serve` e nos interesses.

Há duas superfícies, com credenciais diferentes:

| Superfície | Credencial | Rotas | O que é preciso |
|---|---|---|---|
| **App** | `Authorization: Bearer …` (Sanctum) | `api/ads-proxy/*` | Nada: o package reconhece o Bearer mesmo sem `auth` no middleware. |
| **Site** (browser) | cookie de sessão + CSRF | `anuncios-proxy/*` | Ligar as rotas web e apontar o SDK para lá. |

**As rotas web** (desligadas por omissão):

```dotenv
HONGAYETU_ADS_PROXY_WEB_AUTO_ROUTES=true
```

```tsx
<AdsProvider config={{ baseUrl: '/anuncios-proxy', mode: 'proxy' }}>
```

São as mesmas rotas (`serve`, `impression`, `click`, `video-event`, `interesses` e o `GET click/{token}`), com `web` + `throttle:120,1,anuncios-web` e sem `auth` (os visitantes também vêem anúncios). O `web` traz a verificação de CSRF, e é por isso que as apps ficam nas rotas `api/…`.

**Onde se procura quem pede** (`Identidade::quemPede`), por ordem: os guards de `ads-proxy.guards` (`HONGAYETU_ADS_GUARDS=cliente,web`), o utilizador do pedido, o `sanctum`, os `auth:…` do middleware dos anúncios e o guard por omissão. O atributo da conta no modelo é o de `mensagens.atributo_do_utilizador` (`HONGAYETU_MENSAGENS_ATRIBUTO`, por omissão `honga_user_id`).

| Variável | Default | Descrição |
|---|---|---|
| `HONGAYETU_ADS_PROXY_WEB_AUTO_ROUTES` | `false` | Regista as rotas dos anúncios para o site. |
| `HONGAYETU_ADS_PROXY_WEB_PREFIX` | `anuncios-proxy` | Prefixo dessas rotas (fora de `api/`). |
| `HONGAYETU_ADS_GUARDS` | — | Guards onde procurar primeiro, separados por vírgulas. |

## Interesses por utilizador (desde a 0.13.0)

Quem tem conta Honga Yetu tem um perfil de temas, que a central usa para pontuar os anúncios. A app ou o site do serviço manda os sinais explícitos (uma pesquisa, um item visto, uma compra) a este servidor, que os reencaminha à central em nome da conta ligada:

```
POST /api/ads-proxy/interesses
{ "tipo": "compra | pesquisa | ver_item | ver_ecra", "temas": ["telemoveis", "informatica"] }
```

- `temas`: de 1 a 10 textos de até 60 letras, **já limpos** (categoria, tipo de produto), nunca frases de pesquisa soltas nem temas sensíveis.
- O `honga_user_id` sai sempre do utilizador autenticado neste servidor. Um `honga_user_id` no corpo é ignorado.
- É fire-and-forget: a app não espera pela resposta, não mostra erros e não repete o pedido. Sem sessão, sem conta Honga Yetu ligada, desligado ou com a central em falha, a resposta é `200 {"estado":"ok","registados":0}`. Só um corpo inválido dá `422`.
- A rota não leva `auth`: usa o middleware dos anúncios sem os `auth…` e os `throttle…`, mais `throttle:120,1,interesses` (ou o que puseres em `interesses.middleware`).
- A app chama `api/ads-proxy/interesses` (Bearer); o site chama `anuncios-proxy/interesses` (sessão), com as rotas web ligadas.
- O `/serve` com `keywords` e o clique num anúncio já alimentam o perfil sozinhos: não precisam desta rota.

| Variável | Default | Descrição |
|---|---|---|
| `HONGAYETU_INTERESSES_ENABLED` | `true` | Kill switch: com `false`, responde `registados: 0` sem chamar a central. |
| `HONGAYETU_INTERESSES_BASE_URL` | `https://hongayetu.com/api/v1/servicos/interesses` | Endereço da central. |
| `HONGAYETU_INTERESSES_TOKEN` | o dos anúncios | Token do ConnectedProject. |
| `HONGAYETU_INTERESSES_TIMEOUT` | `3` | Tempo máximo de espera, em segundos. |
| `HONGAYETU_INTERESSES_AUTO_ROUTES` | `true` | `false` não regista a rota. |
| `HONGAYETU_INTERESSES_GUARD` | — | Guard onde se procura quem está autenticado. Sem ele: os `auth:…` do middleware dos anúncios e, depois, o guard por omissão. |

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

### Quem não tem conta Honga Yetu e o filtro (desde a 0.12.0)

- **`mensagens.contas_sem_honga`** (`HONGAYETU_MENSAGENS_CONTAS_SEM_HONGA`, **`false` por omissão**: os serviços já instalados não mudam). Ligado, quem está autenticado sem o atributo da conta Honga Yetu recebe as mensagens na mesma: o proxy manda `conta_externa_id` (o `getAuthIdentifier()` do utilizador, como texto; só `A-Z a-z 0-9 _ . : -` até 64 caracteres) e `conta_nome` (o atributo `mensagens.atributo_do_nome`, ou, sem ele, `nome` e depois `name`). A central lembra-se, por serviço, do que cada conta já viu. Com conta Honga Yetu, continua a ir só o `honga_user_id`. Vale para a lista, os eventos, a API, a web e a directiva `@mensagensPopupHonga`.
- **`mensagens.filtro`**: o nome de uma classe do serviço que implementa `HongaYetu\InternalAdsProxy\Contratos\FiltroDeMensagens` (`aceita(Request $request, mixed $utilizador): bool`), resolvida pelo contentor. Com `false`: lista vazia, evento «ok» sem chamar a central e a directiva não escreve nada. Serve para deixar de fora, por exemplo, os clientes de um revendedor (marca branca). Uma classe que não se resolve recusa (e fica um aviso).

```php
class SoClientesDiretos implements FiltroDeMensagens
{
    public function aceita(Request $request, mixed $utilizador): bool
    {
        return $utilizador->revendedor_id === null;
    }
}
// config/ads-proxy.php → 'mensagens' => ['filtro' => SoClientesDiretos::class, 'contas_sem_honga' => true]
```

| Variável | Default | Descrição |
|---|---|---|
| `HONGAYETU_MENSAGENS_CONTAS_SEM_HONGA` | `false` | Mandar a conta do serviço a quem não tem conta Honga Yetu. |
| `HONGAYETU_MENSAGENS_ATRIBUTO_NOME` | `nome`, depois `name` | Atributo do utilizador com o nome da pessoa. |

## Feedback (desde a 0.12.0)

O terceiro proxy: o **feedback** da central. A app ou o site manda o comentário ao servidor do serviço, que o encaminha à central (`POST https://hongayetu.com/api/v1/servicos/feedback`) com o token do ConnectedProject (o mesmo dos anúncios e das mensagens).

| Rota no serviço | O que faz |
|---|---|
| `POST api/feedback` | Envia o comentário. Responde o corpo da central (`{"estado":"ok"}`). |
| `POST api/feedback/capturas` | `multipart/form-data` com `imagem` (jpeg, png ou webp, até 5 MB). Responde `{"estado":"ok","captura":"<id>"}`. |
| `POST feedback` e `POST feedback/capturas` | O mesmo para os sites, com o grupo `web` (sessão e CSRF). |

**O que a app manda:** `texto`, `tipo`, `estrelas`, `servico` (ou o `servico_por_omissao`), `superficie`, `versao_app`, `plataforma`, `ecra`, `aparelho`, `quer_resposta`, `contacto_tipo`, `contacto`, `enviar_como`, `capturas[]` (até 3 ids devolvidos pelas capturas) e a armadilha `site` (preenchida, responde `ok` sem chamar a central). Tudo o resto é ignorado.

**O que sai sempre do servidor, nunca do pedido:**
- a conta: o `honga_user_id` (se o utilizador o tiver) ou, com `feedback.contas_sem_honga` (**ligado por omissão**: no feedback faz sentido saber sempre quem escreve), o `conta_externa_id` e o `conta_nome`. Com `enviar_como=0` não vai conta nenhuma. Quem está autenticado procura-se no `feedback.guard` (`HONGAYETU_FEEDBACK_GUARD`; na API, normalmente `sanctum`); sem ninguém, é um visitante;
- a origem (`origem_tipo`, `origem_id`, `origem_nome`), de uma classe do serviço em `feedback.origem` que implementa `HongaYetu\InternalAdsProxy\Contratos\OrigemDoFeedback` (`origem(Request $request, mixed $utilizador): ?array`, com `tipo`, `id` e `nome`). É assim que o GPS Yetu diz de que revendedor é o cliente.

**Capturas:** a central recebe endereços `https`, não ficheiros. Por isso `…/capturas` guarda a imagem no disco `feedback.disco` (`public` por omissão, que tem de ser público; pasta `feedback/capturas`; nome aleatório) e o envio troca os ids de `capturas[]` pelos endereços (`Storage::disk()->url()`, forçados a `https`). Ids que não existem ou não são nossos são ignorados.

**Respostas:** o 200, o 422 e o 429 da central passam tal e qual. Se a central falhar (rede, tempo esgotado, 5xx, o 401 do token), responde `503 {"estado":"erro","texto":"Não foi possível enviar o teu comentário. Tenta de novo."}` e fica um aviso no log: ao contrário das mensagens, aqui a pessoa tem de saber que não seguiu. `feedback.enabled=false` também responde 503.

**Limites e middleware:** por omissão o envio leva só `throttle:10,1,feedback`, e as capturas `throttle:12,1,feedback-capturas` (o feedback aceita visitantes, por isso sem `auth`). Se acrescentares `auth:sanctum` ao `middleware`, acrescenta-o também ao `capturas_middleware`. As rotas web: `middleware` e `capturas_middleware` em `feedback.web`.

| Variável | Default | Descrição |
|---|---|---|
| `HONGAYETU_FEEDBACK_ENABLED` | `true` | Kill switch do feedback (503 sem chamar a central). |
| `HONGAYETU_FEEDBACK_BASE_URL` | `https://hongayetu.com/api/v1/servicos/feedback` | Endereço da central. |
| `HONGAYETU_FEEDBACK_TOKEN` | o das mensagens, depois o dos anúncios | Token do ConnectedProject. |
| `HONGAYETU_FEEDBACK_TIMEOUT` | `8` | Tempo máximo de espera, em segundos. |
| `HONGAYETU_FEEDBACK_AUTO_ROUTES` | `true` | Registar as rotas da API sozinho. |
| `HONGAYETU_FEEDBACK_PREFIX` | `api/feedback` | Prefixo das rotas da API. |
| `HONGAYETU_FEEDBACK_GUARD` | (o por omissão) | Guard da API onde se procura quem está autenticado. |
| `HONGAYETU_FEEDBACK_CONTAS_SEM_HONGA` | `true` | Mandar a conta do serviço a quem não tem conta Honga Yetu. |
| `HONGAYETU_FEEDBACK_SERVICO` | o das mensagens | Serviço usado quando o pedido não traz `servico`. |
| `HONGAYETU_FEEDBACK_DISCO` | `public` | Disco das capturas. |
| `HONGAYETU_FEEDBACK_WEB_AUTO_ROUTES` | `true` | Registar as rotas web sozinho. |
| `HONGAYETU_FEEDBACK_WEB_PREFIX` | `feedback` | Prefixo das rotas web. |
| `HONGAYETU_FEEDBACK_WEB_GUARD` | (o por omissão) | Guard das rotas web. |

Quem publicou o `config/ads-proxy.php` copia o bloco `feedback` e as chaves novas de `mensagens` (`contas_sem_honga`, `atributo_do_nome`, `filtro`). Sem elas valem os valores por omissão acima, com as rotas do feedback registadas sem `auth`.

## Mensagens em popup nos sites (desde a 0.11.0)

Para os **sites** dos serviços (Inertia + React, Blade, o que for) não é preciso componente nem `npm`: o package serve um `popup.js` autónomo, que desenha a janela, e uma directiva Blade que o carrega.

### Ligar um site

1. **A linha de Blade**, na página de raiz do site (a `app.blade.php` do Inertia), antes do `</body>` ou no `<head>`:

   ```blade
   @mensagensPopupHonga
   ```

   Só escreve alguma coisa quando há sessão (com o id da conta Honga Yetu preenchido) e zonas configuradas: uma etiqueta `<script defer …>`, sem dados da pessoa.

2. **As zonas**, no `config/ads-proxy.php` do serviço (`php artisan vendor:publish --tag=hongayetu-ads-proxy-config`, se ainda não está publicado):

   ```php
   'mensagens' => [
       // …
       'web' => [
           // …
           'zonas' => [
               ['servico' => 'humbi', 'superficie' => 'site', 'caminhos' => ['/'], 'anfitrioes' => ['humbi.ao']],
               ['servico' => 'humbi-negocio', 'superficie' => 'painel', 'caminhos' => ['/painel'], 'anfitrioes' => ['negocio.humbi.ao']],
           ],
       ],
   ],
   ```

   | Chave | O que é |
   |---|---|
   | `servico` | A chave do serviço na central. |
   | `superficie` | `site`, `painel` ou `portal`. A `app` é só das rotas da API. |
   | `caminhos` | Os caminhos **exactos** da página inicial, sem barra final (`/` é a raiz). A janela só abre neles. |
   | `anfitrioes` | Os domínios da zona. Vazio = qualquer domínio. |

   A primeira zona que casar com o domínio e o caminho da página ganha.

3. **O middleware e o guard**, se o site não usa o guard por omissão:

   ```php
   'middleware' => ['web', 'auth:cliente', 'throttle:60,1,mensagens-popup-web'], // throttle sempre com nome
   'guard' => 'cliente', // o guard onde a directiva procura a sessão
   ```

> ⚠️ **Quem já tem o `config/ads-proxy.php` publicado** copia o bloco `mensagens.web` do config do package para o ficheiro publicado (o `mergeConfigFrom` não junta chaves aninhadas). Sem o bloco valem os valores por omissão, mas não há zonas, e a directiva não escreve nada.

### As rotas

| Rota no serviço | Middleware | O que faz |
|---|---|---|
| `GET mensagens-popup?servico=…&superficie=…` | `web`, `auth`, `throttle:60,1,mensagens-popup-web` | A lista. O `servico` e a `superficie` têm de ser os de uma zona (e do domínio dela): de outro modo vem vazia, sem se chamar a central. |
| `POST mensagens-popup/{mensagem}/eventos` | os mesmos | Os eventos. Fora de uma zona responde «ok» sem chamar a central. |
| `GET mensagens-popup/janela` | `throttle:120,1,mensagens-popup-script` | O script. Público, sem sessão, `Cache-Control: public, max-age=3600`; a directiva põe-lhe `?v=<hash do ficheiro>`. |

A conta sai sempre da sessão (`$request->user()`), como nas rotas da API. Sem sessão, a lista e os eventos respondem `401`.

### O que o script faz

- Pede a lista só na página inicial de uma zona, uma vez por zona e por carregamento. Acompanha a navegação do Inertia (`inertia:navigate`) e o botão de recuar; com `?tutorial` no endereço não abre.
- Mostra uma mensagem de cada vez: diapositivos (**Anterior** / **Seguinte**), imagem ou vídeo (MP4 e HLS), os botões da mensagem, **Não mostrar mais** nas dispensáveis, «Continuar em N s» nas obrigatórias.
- Regista `vista`, `fechada`, `dispensada`, `clique`, `video_progresso` e `video_fim`.
- Vive num Shadow DOM: o CSS do site não o estraga, nem ele o do site. Tema claro/escuro pelo sistema, ou pela classe `dark` / `light` no `<html>`.
- Nunca parte a página: sem rede, com a central em baixo ou com uma resposta estranha, não aparece nada.

A cor do botão principal acerta-se no CSS do site, se for preciso:

```css
:root {
    --mensagens-popup-destaque: #0f766e;        /* fundo do botão principal */
    --mensagens-popup-sobre-destaque: #fff;      /* texto do botão principal */
    --mensagens-popup-destaque-escuro: #2dd4bf;  /* os mesmos, no tema escuro */
    --mensagens-popup-sobre-destaque-escuro: #042f2e;
}
```

### A saber

- **Sessão iniciada sem recarregar** (login por uma visita do Inertia): a etiqueta só é escrita quando a página de raiz é desenhada com sessão, por isso as mensagens aparecem no carregamento seguinte.
- **`Content-Security-Policy`**: o site tem de deixar passar o script do próprio domínio e, para o HLS fora do Safari, `https://cdnjs.cloudflare.com` (o `hls.js` só é carregado quando há um vídeo HLS para tocar; se for bloqueado, fica a capa).
- **Imagens e vídeos só `https`.**

| Variável | Default | Descrição |
|---|---|---|
| `HONGAYETU_MENSAGENS_WEB_ENABLED` | `true` | Kill switch das mensagens nos sites: a directiva deixa de escrever e as rotas respondem vazio. |
| `HONGAYETU_MENSAGENS_WEB_AUTO_ROUTES` | `true` | Registar as rotas web sozinho. |
| `HONGAYETU_MENSAGENS_WEB_PREFIX` | `mensagens-popup` | Prefixo das rotas web. |
| `HONGAYETU_MENSAGENS_WEB_GUARD` | (o por omissão) | O guard onde a directiva procura a sessão. |

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

O `popup.js` prova-se num browser a sério, com o Playwright do monólito (Chromium, sem servidor nenhum):

```bash
node --check resources/js/popup.js
node tests/harness/prova.js            # tudo; ou só uma parte: principal, fechar, navegacao, seguranca, falhas, video, temas, telemovel, csrf
```

As capturas ficam em `tests/harness/capturas/`. O Playwright vem de `PLAYWRIGHT_DIR` (por omissão, o `node_modules/playwright` do monólito).

## Licença

Proprietary. Uso restrito ao ecossistema HongaYetu.

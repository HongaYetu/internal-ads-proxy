# Changelog

Todas as alterações relevantes deste package estão documentadas aqui.

O formato segue [Keep a Changelog](https://keepachangelog.com/) e o versionamento segue [SemVer](https://semver.org/) — com a ressalva (típica de packages `internal-*`) que pode haver breaking changes em releases minor se sincronizados com a central.

## [0.9.2] — 2026-10-29

### Corrigido
- **O limite de pedidos do proxy passa a ter contador próprio.** O `middleware` por omissão trazia `throttle:60,1`, sem nome. Um `throttle:N,M` sem o 3.º argumento usa a mesma chave — o utilizador, ou o domínio e o IP — em **todas** as rotas da app, seja qual for o N: os pedidos dos anúncios de um ecrã (serve, impressões, cliques, vídeo) gastavam o limite das outras rotas. No DesAppego esgotavam o limite do login, e a revisão do Google recusou a app a 28/10/2026. Passa a `throttle:60,1,anuncios`. Teste `AdsProxyLimiteDePedidosTest`.

### Atenção
- Só muda nada para quem usa a configuração por omissão. Quem publicou o `config/ads-proxy.php` fica com o `middleware` que lá tem — o `mergeConfigFrom` não toca numa chave que a app já define —, e deve dar nome ao `throttle` à mão (ex.: `'throttle:60,1,anuncios'`).

## [0.9.0] — 2026-08-19

### Corrigido
- **O clique deixa de aterrar na página inicial.** O `GET /click/{token}` reencaminhava para a central sem `device_id` — porque o pedido nasce neste servidor e não no aparelho — e levava `403`. Caía então no `click_fallback_url`, ou seja, **todos** os cliques desta rede iam parar à home em vez do site do anunciante, e nenhum era contado. A central passou a aceitar a ausência de aparelho no clique (a divergência continua recusada); este package passa a declarar quem é o cliente.

### Adicionado
- `impression`, `click` e o `GET /click/{token}` enviam `client_ip` e `client_user_agent` — o IP e o agente de **quem viu o anúncio**, não os deste servidor. Sem eles a central via sempre a mesma origem e o guarda anti-fraude, que conta por IP e por minuto, lia uma aplicação inteira como um aparelho a martelar: a partir de certo tráfego descartava cliques legítimos em silêncio.
- Aviso no log (`hongayetu.ads_proxy.clique_sem_destino`) sempre que o clique cai no fallback. O fallback é um desfecho legítimo; ser invisível não é — foi o que deixou o `403` acima passar despercebido.

### Nota sobre as versões em falta

As **0.7.0 e 0.8.0 nunca foram publicadas**. Estavam descritas neste ficheiro e o
código delas ficou na árvore de trabalho, por commitar — e a 0.6.0 tinha commit
mas ficou sem tag. Quem instalava pelo composer recebia sempre a **0.5.0**, e por
isso nem as palavras-chave de conteúdo nem o vídeo alguma vez saíram daqui.

O conteúdo das três vai nesta versão, tal como estava escrito:

- **(era a 0.8.0)** `serve` aceita `suporta_video` (boolean, opcional) — o consumidor declara que sabe reproduzir vídeo. A central usa-o para decidir qual dos assets vem marcado com `preferido: true`; a resposta continua a trazer imagem **e** vídeo, pelo que um cliente que não mande o campo (ou não saiba tocar vídeo) continua a receber imagem exactamente como antes.
- **(era a 0.7.0)** `serve` aceita `keywords: ["tecnologia", ...]` (opcional, máx. 20, 40 caracteres cada) — as palavras-chave do conteúdo que está no ecrã. O proxy limita-se a deixá-las passar; a normalização e a pontuação são da API central. Campanhas sem palavras-chave continuam elegíveis: isto pontua, não filtra.
- O `POST /video-event` passa a ser encaminhado. O SDK chama-o desde que conta quartis, e este proxy não tinha por onde o mandar.

### Requer
- API central com `client_ip`/`client_user_agent` aceites no tracking (>= 2026-08-19).
- Quem tinha `^0.7` ou `^0.8` no composer.json **não instalava nada** — essas versões nunca existiram como tag. Passar a `^0.9`.

## [0.6.0] — 2026-05-30

> Tinha commit e ficou **sem tag** até 2026-08-19. Publicada agora, sobre o commit original.

### Adicionado
- `serve` aceita `formatos_aceites: [{largura,altura}, ...]` (opcional, máx. 14 entradas). Quando definido, a central só devolve assets cuja dimensão coincida EXACTAMENTE com uma das entradas; sem match devolve `data: null` (no-fill). Quando omitido mantém o matching aproximado actual.

## [0.5.0] — 2026-05-29

### Adicionado
- `serve` aceita `slot_width` e `slot_height` (px) — propagados ao upstream para que a central possa devolver o asset com dimensões mais próximas (asset versioning).

## [0.4.1] — 2026-05-29

### Breaking
- `serve` deixou de aceitar `origem`. O `espaco_slug` já identifica o app — a central deriva `origem_id` automaticamente.

## [0.4.0] — 2026-05-29

### Breaking
- `serve` agora **exige** `espaco_slug` (string). `espaco_id` foi removido. Slug é estável entre ambientes; IDs podem variar entre dev/prod.

## [0.3.0] — 2026-05-29

### Adicionado
- `serve` aceita `espaco_slug` como alternativa a `espaco_id`. Identificador estável entre ambientes (dev/prod podem ter IDs diferentes).

## [0.2.0] — 2026-05-29

### Adicionado
- **Kill switch** via `HONGAYETU_ADS_ENABLED` (default `true`). Quando `false`, todos os endpoints (`serve`/`impression`/`click`) devolvem `{estado:ok, data:null, meta:{reason:'ads_disabled'}}` sem chamar a central. O SDK degrada graciosamente. Útil para incidentes, A/B testing ou pausa temporária sem revogar tokens nem fazer deploy do app.

## [0.1.0] — 2026-05-29

### Adicionado
- Primeira versão pública.
- `AdsProxyServiceProvider` com auto-discovery Laravel — basta `composer require` e tudo fica registado.
- `AdsProxyController` com 3 endpoints: `POST /serve`, `POST /impression`, `POST /click` (auto-registados em `api/ads-proxy/*` por defeito).
- `AdsProxyClient` HTTP service injectável — útil para chamar a API server-side directamente (ex: render Inertia/Blade).
- Config publishable via `php artisan vendor:publish --tag=hongayetu-ads-proxy-config`.
- Variáveis de env: `HONGAYETU_ADS_BASE_URL`, `HONGAYETU_ADS_TOKEN`, `HONGAYETU_ADS_TIMEOUT`, `HONGAYETU_ADS_PROXY_PREFIX`, `HONGAYETU_ADS_PROXY_AUTO_ROUTES`, `HONGAYETU_ADS_LOG_CHANNEL`.
- Suporte a Laravel 10, 11, 12.

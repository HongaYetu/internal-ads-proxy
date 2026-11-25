# Changelog

Todas as alterações relevantes deste package estão documentadas aqui.

O formato segue [Keep a Changelog](https://keepachangelog.com/) e o versionamento segue [SemVer](https://semver.org/) — com a ressalva (típica de packages `internal-*`) que pode haver breaking changes em releases minor se sincronizados com a central.

## [0.11.1] — 2026-11-25

### Corrigido
- O script da janela passa a servir-se em `mensagens-popup/janela`, sem `.js` no endereço. Nos servidores com a regra do nginx para ficheiros `.js`/`.css` (aaPanel), um endereço acabado em `.js` que não é um ficheiro em disco chegava ao Laravel mas saía com o estado 404, e o browser recusa um `<script>` com 404.

## [0.11.0] — 2026-11-25

### Adicionado
- **As mensagens em popup nos sites dos serviços, sem build de frontend.** O package passa a servir um ficheiro JavaScript puro e autónomo (`resources/js/popup.js`, sem dependências nem bundler) que desenha a janela, e uma directiva Blade que cada site põe na página de raiz. Ligar um site é uma linha de Blade e as zonas no config, sem `npm`:
  ```blade
  @mensagensPopupHonga
  ```
- **Rotas web (sessão), além das da API:**
  - `GET mensagens-popup` (query `servico`, `superficie`) → a lista;
  - `POST mensagens-popup/{mensagem}/eventos` → os eventos;
  - `GET mensagens-popup/popup.js` → o script, **sem** sessão nem `auth` (só `throttle`), com `Content-Type: application/javascript; charset=utf-8`, `Cache-Control: public, max-age=3600` e `ETag`. A directiva junta-lhe `?v=<hash do ficheiro>`.
- **Zonas** (`mensagens.web.zonas`): onde a janela pode abrir e em nome de que serviço — `['servico' => 'humbi', 'superficie' => 'site'|'painel'|'portal', 'caminhos' => ['/'], 'anfitrioes' => []]`. Na web, o `servico` e a `superficie` vêm do pedido mas têm de ser os de uma zona configurada (e, se a zona nomear domínios, o pedido tem de vir de um): de outro modo a lista vem vazia e o evento vem «ok», sem se chamar a central. A superfície `app` não se aceita pelas rotas web. A conta continua a sair sempre da sessão.
- **Directiva `@mensagensPopupHonga`**: escreve uma única etiqueta `<script defer src="…/popup.js?v=…" data-raiz data-zonas data-csrf>`, e só com as mensagens ligadas, zonas configuradas e alguém autenticado no guard (com o id da conta Honga Yetu preenchido). Sem dados da pessoa no HTML. Um erro aqui dá em nada escrito, nunca numa página partida.
- **O `popup.js`** segue o `AnfitriaoDeMensagens` do monólito:
  - só pede a lista na página inicial de uma zona (domínio + caminho exacto), uma vez por zona e por carregamento; reage ao `inertia:navigate` e ao `popstate`; com `?tutorial` no endereço não abre;
  - uma mensagem de cada vez; diapositivos com **Anterior** / **Seguinte** e indicador; imagem ou vídeo (MP4, ou HLS nativo, ou com o `hls.js` 1.5.17 do cdnjs carregado a pedido — se falhar, fica a capa); botões da mensagem no rodapé; **Não mostrar mais** nas dispensáveis; nas obrigatórias, «Continuar em N s» sem Esc nem clique fora;
  - eventos `vista` (1 s depois de abrir), `fechada`, `dispensada`, `clique` (`principal`, `secundario`, `ligacao_texto`), `video_progresso` (no máximo de 10 em 10 s) e `video_fim`, sempre com o `servico` e a `superficie` da zona;
  - tudo dentro de um Shadow DOM (o CSS do site não lhe toca, nem o dela ao site), tema claro/escuro (`prefers-color-scheme`, ou a classe `dark`/`light` no `<html>`), foco preso, alvos de 44 px, `prefers-reduced-motion`;
  - o HTML dos textos volta a ser limpo no browser (lista branca de etiquetas, só o `href`; `http(s)`, `mailto:`, `tel:` e caminhos relativos), imagens e vídeos só `https`, e nunca se escreve HTML por `innerHTML`;
  - falhas de rede ou respostas estranhas: silêncio total.
- Configuração nova na chave `mensagens.web`: `enabled` (`HONGAYETU_MENSAGENS_WEB_ENABLED`), `auto_register_routes`, `route_prefix` (`mensagens-popup`), `route_name_prefix` (`mensagens-popup.web.`), `middleware` (`web`, `auth`, `throttle:60,1,mensagens-popup-web`), `script_middleware` (`throttle:120,1,mensagens-popup-script`), `guard` e `zonas`.
- Prova num browser a sério em `tests/harness/` (`node tests/harness/prova.js`, com o Playwright do monólito): 100 verificações e as capturas em `tests/harness/capturas/`.

### Atenção
- **As rotas web registam-se sozinhas** ao actualizar (`mensagens-popup`, com `web` + `auth`). Sem zonas não servem para nada: a lista vem vazia e a directiva não escreve nada. Quem não as quiser põe `HONGAYETU_MENSAGENS_WEB_AUTO_ROUTES=false`.
- **Quem publicou o `config/ads-proxy.php` tem de copiar o bloco `mensagens.web`** do config do package para o ficheiro publicado: é lá que se escrevem as zonas. Sem o bloco, valem os valores por omissão (incluindo o `middleware`), mas não há zonas.
- Um site com outro guard acerta as duas coisas: `'auth:cliente'` no `mensagens.web.middleware` e `'guard' => 'cliente'`.
- A etiqueta só é escrita para quem tem sessão **quando a página de raiz é desenhada**. Num site Inertia, quem inicia sessão sem recarregar a página só vê as mensagens no carregamento seguinte.
- Um site com `Content-Security-Policy` tem de deixar passar o script do próprio domínio e, para o HLS fora do Safari, `https://cdnjs.cloudflare.com`.

## [0.10.0] — 2026-11-25

- Aceita o Laravel 13 (`illuminate/* ^13.0`).

### Adicionado
- **Segundo proxy: as mensagens em popup da central Honga Yetu.** As apps e os sites de cada serviço passam a pedir as mensagens ao servidor do próprio serviço, e este vai buscá-las à central com o token do ConnectedProject, em nome da conta Honga Yetu de quem está autenticado. Os caminhos e a forma são os da API pública da central, para a app só mudar o endereço base:
  - `GET api/mensagens-popup` (query `servico`, `superficie`, `versao_app`, `plataforma`) → `{"estado":"ok","mensagens":[...]}`;
  - `POST api/mensagens-popup/{mensagem}/eventos` (`tipo` e os opcionais `servico`, `superficie`, `versao_app`, `plataforma`, `botao`, `destino`, `segundos`, `contexto_tipo`, `contexto_id`, `contexto_nome`) → `{"estado":"ok"}`.
- **A conta sai sempre da sessão deste servidor** (`auth()->user()->honga_user_id`, com o nome do atributo configurável em `mensagens.atributo_do_utilizador`). Um `honga_user_id` que venha no pedido é ignorado, tal como o `empresa_id` e o `escola_id`.
- **As mensagens nunca partem a entrada da app.** Desligado (`HONGAYETU_MENSAGENS_ENABLED=false`), sem conta Honga Yetu, com a central em baixo, lenta ou a recusar, a lista responde `{"estado":"ok","mensagens":[]}` (200) e o evento `{"estado":"ok"}`, com um aviso no canal de logs do package (`hongayetu.mensagens_popup.*`). Só as recusas da central a um evento (4xx, por exemplo o 422 «Esta mensagem não se pode dispensar.») passam tal e qual para a app.
  - Excepção: um `401` da central num evento **não** passa. É o token do serviço a ser recusado, não a sessão da pessoa, e uma app que veja um 401 costuma terminar a sessão. Responde `{"estado":"ok"}` e fica o aviso no log.
- Configuração nova na chave `mensagens` do `config/ads-proxy.php`: `enabled`, `base_url`, `token` (por omissão o dos anúncios), `timeout` (5 s), `auto_register_routes`, `route_prefix`, `route_name_prefix`, `middleware` (`auth:sanctum` + `throttle:60,1,mensagens-popup`, com contador próprio), `atributo_do_utilizador`, `servicos_permitidos` e `servico_por_omissao`.
- `MensagensPopupClient` (`listar`, `registarEvento`), para quem queira ir buscar as mensagens do lado do servidor.
- Nada fica em cache.

### Atenção
- **As rotas novas registam-se sozinhas** ao actualizar o package (`api/mensagens-popup`, com `auth:sanctum`). Um serviço que use outro guarda acerta o `mensagens.middleware`; quem não as quiser põe `HONGAYETU_MENSAGENS_AUTO_ROUTES=false`.
- Quem publicou o `config/ads-proxy.php` não tem a chave `mensagens`: o package usa os valores por omissão (incluindo o `middleware`), mas as variáveis `HONGAYETU_MENSAGENS_*` só passam a contar depois de copiar o bloco `mensagens` para o ficheiro publicado.

### Requer
- A central com `GET`/`POST` em `/api/v1/servicos/mensagens-popup` (autenticação pelo token do ConnectedProject).

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

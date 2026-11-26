# Changelog

Todas as alterações relevantes deste package estão documentadas aqui.

O formato segue [Keep a Changelog](https://keepachangelog.com/) e o versionamento segue [SemVer](https://semver.org/) — com a ressalva (típica de packages `internal-*`) que pode haver breaking changes em releases minor se sincronizados com a central.

## [0.13.0] — 2026-11-26

### Adicionado
- **Anúncios por peças.** O `POST /serve` passa a aceitar `aceita_nativo` (boolean) e a encaminhá-lo para a central. É o campo com que um cliente que sabe desenhar o anúncio com os seus próprios componentes (os SDK web `@hongayetu/internal-ads-react` ≥ 0.10.0, React Native ≥ 0.10.0 e o `sdk/ads` das apps KMP) diz que aceita um anúncio só com peças, mesmo sem asset pronto no formato pedido. Antes, o `validate` deitava o campo fora em silêncio e esses anúncios nunca chegavam aos sites e às apps dos serviços.
- A resposta não muda neste package: o proxy já devolvia o corpo da central tal e qual, por isso a chave `nativo` de cada anúncio chega ao cliente sem mais nada.

- **Interesses por utilizador** (chave `interesses`): `POST {prefixo do proxy}/interesses` (por omissão `api/ads-proxy/interesses`), ao lado do `serve`. A app ou o site manda `{ "tipo": "compra | pesquisa | ver_item | ver_ecra", "temas": ["…"] }` (1 a 10 temas, até 60 letras cada) e este servidor reencaminha para `POST https://hongayetu.com/api/v1/servicos/interesses`, com o token do ConnectedProject e o `honga_user_id` da conta Honga Yetu de quem está autenticado.
  - É fire-and-forget. Sem sessão, sem conta Honga Yetu ligada, com `interesses.enabled` a `false` ou com a central em falha (erro, sem rede, tempo esgotado), responde `200 {"estado":"ok","registados":0}`. Com sucesso devolve o `registados`, o `ignorados` e o `motivo` da central. Só um corpo inválido dá `422 {"estado":"erro","texto":"…"}`.
  - O tempo de espera é de 3 segundos (`HONGAYETU_INTERESSES_TIMEOUT`).
  - A rota **não leva `auth`**: por omissão usa o middleware dos anúncios sem os `auth…` e os `throttle…`, mais `throttle:120,1,interesses`. Quem está autenticado procura-se no `interesses.guard` ou, sem ele, nos `auth:…` do middleware dos anúncios e depois no guard por omissão.
  - Um `honga_user_id` no corpo do pedido é sempre ignorado.
- Variáveis de ambiente novas: `HONGAYETU_INTERESSES_ENABLED`, `HONGAYETU_INTERESSES_BASE_URL`, `HONGAYETU_INTERESSES_TOKEN`, `HONGAYETU_INTERESSES_TIMEOUT`, `HONGAYETU_INTERESSES_AUTO_ROUTES` e `HONGAYETU_INTERESSES_GUARD`.

- **Os anúncios nos sites** (chave `web`, **desligada por omissão**): um segundo conjunto das mesmas rotas, fora de `api/` e com o grupo `web` — `POST anuncios-proxy/serve|impression|click|video-event|interesses` e `GET anuncios-proxy/click/{token}`. É para o browser, com cookie de sessão e CSRF. Liga-se com `HONGAYETU_ADS_PROXY_WEB_AUTO_ROUTES=true` (ou `web.auto_register_routes` no config) e o site aponta o SDK para lá: `baseUrl: '/anuncios-proxy'`. As rotas `api/ads-proxy/*` ficam como estão, para as apps.
- `ads-proxy.guards` (`HONGAYETU_ADS_GUARDS`, separados por vírgulas): guards onde se procura primeiro quem está autenticado.

### Corrigido
- **A conta Honga Yetu não chegava à central** nos serviços cujas rotas dos anúncios não levam `auth` (as que servem visitantes). O `serve` perguntava ao guard por omissão (`auth()->check()`), que é o da sessão: não via o Bearer da app, e nas rotas `api/…` nem sessão havia para o site. O `honga_user_id` nunca seguia, e a central servia toda a gente como visitante.
  - Há agora um único sítio que descobre quem pede, `Identidade::quemPede()`, usado pelo `serve`, pelos interesses, pelas mensagens em popup e pelo feedback. Procura, por ordem: o guard pedido por quem chama (`interesses.guard`, `feedback.guard`), os de `ads-proxy.guards`, o utilizador do pedido (`$request->user()`: o que um `auth:…` ou um middleware do serviço já resolveu), o `sanctum` se existir (reconhece o Bearer da app mesmo sem `auth` na rota), os `auth:…` do middleware dos anúncios e o guard por omissão.
  - Para o site, a sessão só existe com o grupo `web`: é para isso que servem as rotas web acima.
- O `serve` passa a ler a conta pelo atributo configurado (`mensagens.atributo_do_utilizador`, por omissão `honga_user_id`), como as mensagens: num serviço que lhe chama outra coisa (`honga_id`), a conta não seguia.

### Verificado
- No `serve`, um `honga_user_id` escrito pelo cliente no corpo não segue para a central, com ou sem sessão: só vai o da conta de quem está autenticado. Já era assim (o `validate` só devolve os campos das regras); fica agora coberto por testes.

### Atenção
- Sem actualizar para esta versão, um cliente novo continua a funcionar, mas só recebe anúncios com asset no formato pedido (o comportamento de sempre).
- **A rota dos interesses regista-se sozinha** ao actualizar. `HONGAYETU_INTERESSES_AUTO_ROUTES=false` não a regista; `HONGAYETU_INTERESSES_ENABLED=false` deixa-a a responder `registados: 0`.
- Quem publicou o `config/ads-proxy.php` não precisa de copiar o bloco `interesses`: sem ele valem os valores por omissão.
- **Um site que chama `api/ads-proxy/*` do browser continua sem conta** enquanto não passar para as rotas web: liga-as e muda o `baseUrl` do SDK para `/anuncios-proxy`. O grupo `web` traz o CSRF: o SDK `@hongayetu/internal-ads-react` ≥ 0.10.0 manda o cabeçalho sozinho; quem chama à mão manda `X-CSRF-TOKEN` ou `X-XSRF-TOKEN`.
- Quem publicou o `config/ads-proxy.php` não precisa de copiar os blocos `guards` e `web` para os valores por omissão valerem (são chaves de primeiro nível); copia o `web` se quiseres mudar o prefixo ou o middleware.
- O feedback e as mensagens passam a reconhecer também o Bearer da app em rotas sem `auth` (antes só viam o guard configurado ou o por omissão). Nas rotas com `auth:…` nada muda.

## [0.12.0] — 2026-11-26

### Adicionado
- **Mensagens para quem não tem conta Honga Yetu.** Opção `mensagens.contas_sem_honga` (`HONGAYETU_MENSAGENS_CONTAS_SEM_HONGA`, **`false` por omissão**: nada muda nos serviços já instalados). Ligada, quem está autenticado sem o atributo da conta Honga Yetu recebe as mensagens na mesma: o proxy manda `conta_externa_id` (o `getAuthIdentifier()`, como texto) e `conta_nome` (`mensagens.atributo_do_nome`, ou `nome` e depois `name`). Com conta Honga Yetu continua a ir o `honga_user_id`. Vale para a lista e os eventos (API e web) e para a directiva `@mensagensPopupHonga`.
- **Filtro de quem recebe.** Opção `mensagens.filtro` (classe do serviço que implementa `Contratos\FiltroDeMensagens`, `aceita(Request, mixed $utilizador): bool`). Com `false`: lista vazia, evento «ok» sem chamar a central e a directiva não escreve nada. Um filtro que não se resolve recusa e regista um aviso.
- **Proxy do feedback** (chave `feedback`): `POST api/feedback` e `POST api/feedback/capturas` na API e `POST feedback` e `POST feedback/capturas` nos sites (grupo `web`), encaminhados para `POST https://hongayetu.com/api/v1/servicos/feedback` com o token do ConnectedProject.
  - A app só manda `texto`, `tipo`, `estrelas`, `servico`, `superficie`, `versao_app`, `plataforma`, `ecra`, `aparelho`, `quer_resposta`, `contacto_tipo`, `contacto`, `enviar_como`, `capturas[]` e a armadilha `site`. A conta (`honga_user_id` ou, com `feedback.contas_sem_honga`, **ligado por omissão**, `conta_externa_id` + `conta_nome`) e a origem (`origem_*`, de uma classe `feedback.origem` que implementa `Contratos\OrigemDoFeedback`) saem sempre do servidor. Com `enviar_como=0` não vai conta.
  - Capturas: a central recebe endereços `https`. O ficheiro (jpeg, png ou webp, até 5 MB) fica no disco `feedback.disco` (`public`), pasta `feedback/capturas`, com nome aleatório; o envio troca os ids (até 3) pelos endereços públicos.
  - Se a central falhar, responde `503 {"estado":"erro","texto":"Não foi possível enviar o teu comentário. Tenta de novo."}` e regista um aviso; o 200, 422 e 429 da central passam tal e qual. Kill switch `feedback.enabled`.
  - Por omissão o middleware é só `throttle:10,1,feedback` (e `throttle:12,1,feedback-capturas` nas capturas): o feedback aceita visitantes.
- Interfaces `HongaYetu\InternalAdsProxy\Contratos\FiltroDeMensagens` e `OrigemDoFeedback`.
- Variáveis de ambiente novas: `HONGAYETU_MENSAGENS_CONTAS_SEM_HONGA`, `HONGAYETU_MENSAGENS_ATRIBUTO_NOME` e `HONGAYETU_FEEDBACK_*` (ver o README).

### Atenção
- **Quem publicou o `config/ads-proxy.php` tem de copiar** as chaves novas de `mensagens` (`atributo_do_nome`, `contas_sem_honga`, `filtro`) e o bloco `feedback` do config do package. Sem elas, valem os valores por omissão: mensagens como antes e as rotas do feedback registadas sozinhas (`HONGAYETU_FEEDBACK_AUTO_ROUTES=false` e `HONGAYETU_FEEDBACK_WEB_AUTO_ROUTES=false` desligam-nas).
- A central tem de ter o suporte a `conta_externa_id` no feedback (o monólito de 26/11/2026 em diante); antes disso, ignora o campo e o feedback segue anónimo.
- Na API, o feedback procura quem está autenticado no `feedback.guard`: sem o definir (`sanctum`, por exemplo) usa o guard por omissão, e o pedido pode seguir como visitante.
- Nenhuma rota acaba em `.js` ou `.css` (lição da 0.11.1).

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

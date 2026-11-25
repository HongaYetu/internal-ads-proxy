<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kill switch
    |--------------------------------------------------------------------------
    | Quando `false`, todos os endpoints proxy devolvem `{estado:ok, data:null}`
    | sem chamar a central. O SDK degrada graciosamente (não renderiza anúncios).
    | Útil para incidentes em produção, A/B testing, ou pausa temporária sem
    | revogar tokens nem fazer deploy do app.
    */
    'enabled' => (bool) env('HONGAYETU_ADS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Upstream — HongaYetu Ads API v2
    |--------------------------------------------------------------------------
    | URL base da central (sem `/serve` etc — só até `/api/v2/ads`).
    | Token Bearer emitido pelo Filament da HongaYetu para este ConnectedProject.
    */
    'base_url' => env('HONGAYETU_ADS_BASE_URL', 'https://anuncios.hongayetu.com/api/v2/ads'),
    'token' => env('HONGAYETU_ADS_TOKEN'),
    'timeout' => (int) env('HONGAYETU_ADS_TIMEOUT', 8),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    | Se `auto_register_routes=true` (default), o ServiceProvider regista
    | `POST {prefix}/serve|impression|click` com o middleware indicado.
    | Pôr `false` para gerir as rotas manualmente.
    */
    'auto_register_routes' => env('HONGAYETU_ADS_PROXY_AUTO_ROUTES', true),
    'route_prefix' => env('HONGAYETU_ADS_PROXY_PREFIX', 'api/ads-proxy'),
    'route_name_prefix' => 'ads-proxy.',
    'middleware' => [
        // Por defeito exige sessão autenticada. Override conforme stack:
        // 'auth:api', 'auth:web', etc.
        'auth:sanctum',
        // Defesa em profundidade — upstream já tem rate limit por token.
        // Com nome próprio (o 3.º argumento): sem ele, o contador é o mesmo de
        // todas as rotas da app com `throttle` sem nome, e os anúncios de um
        // ecrã gastavam o limite de outras (no DesAppego, o do login).
        'throttle:60,1,anuncios',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    | Canal Laravel para warnings de falhas upstream.
    */
    'log_channel' => env('HONGAYETU_ADS_LOG_CHANNEL', null),

    /*
    |--------------------------------------------------------------------------
    | Mensagens em popup
    |--------------------------------------------------------------------------
    | O segundo proxy deste package: as mensagens em popup da central Honga
    | Yetu. A app pede-as a este servidor (`GET {route_prefix}` e
    | `POST {route_prefix}/{mensagem}/eventos`) e este vai buscá-las à central
    | em nome da conta Honga Yetu de quem está autenticado.
    |
    | As mensagens nunca partem a entrada da app: desligado, sem conta ou com
    | a central em baixo, a lista responde vazia e o evento responde «ok».
    */
    'mensagens' => [
        // Kill switch só das mensagens (os anúncios têm o deles, acima).
        'enabled' => (bool) env('HONGAYETU_MENSAGENS_ENABLED', true),

        'base_url' => env('HONGAYETU_MENSAGENS_BASE_URL', 'https://hongayetu.com/api/v1/servicos/mensagens-popup'),
        // Por omissão, o token dos anúncios: é o mesmo ConnectedProject.
        'token' => env('HONGAYETU_MENSAGENS_TOKEN', env('HONGAYETU_ADS_TOKEN')),
        'timeout' => (int) env('HONGAYETU_MENSAGENS_TIMEOUT', 5),

        'auto_register_routes' => env('HONGAYETU_MENSAGENS_AUTO_ROUTES', true),
        'route_prefix' => env('HONGAYETU_MENSAGENS_PREFIX', 'api/mensagens-popup'),
        'route_name_prefix' => 'mensagens-popup.',
        'middleware' => [
            'auth:sanctum',
            // Com nome próprio, pela mesma razão do `throttle` dos anúncios.
            'throttle:60,1,mensagens-popup',
        ],

        // O atributo do utilizador deste serviço que guarda o id da conta
        // Honga Yetu. Aceita um caminho com pontos (ex.: `conta.honga_id`).
        'atributo_do_utilizador' => env('HONGAYETU_MENSAGENS_ATRIBUTO', 'honga_user_id'),

        // O atributo do utilizador com o nome da pessoa, para as contas sem conta
        // Honga Yetu. Aceita um caminho com pontos. Sem valor, tenta `nome` e
        // depois `name`.
        'atributo_do_nome' => env('HONGAYETU_MENSAGENS_ATRIBUTO_NOME'),

        // Quem não tem conta Honga Yetu (o atributo acima vazio) recebe as
        // mensagens na mesma? Ligado, o proxy manda o identificador do
        // utilizador neste serviço (`getAuthIdentifier()`) e o nome, e a central
        // lembra-se, por serviço, do que cada conta já viu. Desligado (omissão),
        // essas pessoas recebem a lista vazia, como antes da 0.12.0.
        'contas_sem_honga' => (bool) env('HONGAYETU_MENSAGENS_CONTAS_SEM_HONGA', false),

        // Quem recebe: o nome de uma classe do serviço que implementa
        // `HongaYetu\InternalAdsProxy\Contratos\FiltroDeMensagens`
        // (`aceita(Request $request, mixed $utilizador): bool`). Com `false`, a
        // lista vem vazia, o evento responde «ok» sem chamar a central e a
        // directiva não escreve nada. Resolve-se pelo contentor. `null` = todos.
        'filtro' => null,

        // Vazia = aceita o `servico` que a app mandar. Preenchida, um
        // `servico` fora dela recebe a lista vazia sem se chamar a central.
        'servicos_permitidos' => [],

        // Usado quando o pedido não traz `servico`.
        'servico_por_omissao' => env('HONGAYETU_MENSAGENS_SERVICO'),

        /*
        |----------------------------------------------------------------------
        | As mensagens nos sites (sessão web), sem build de frontend
        |----------------------------------------------------------------------
        | O package serve um `popup.js` autónomo que desenha a janela, e a
        | directiva `@mensagensPopupHonga`, posta na página de raiz do site,
        | escreve a etiqueta que o carrega. O script pede as mensagens a
        | `GET {route_prefix}` e regista os eventos em
        | `POST {route_prefix}/{mensagem}/eventos`, com a sessão do site.
        |
        | Sem zonas, a directiva não escreve nada.
        */
        'web' => [
            'enabled' => (bool) env('HONGAYETU_MENSAGENS_WEB_ENABLED', true),

            'auto_register_routes' => env('HONGAYETU_MENSAGENS_WEB_AUTO_ROUTES', true),
            'route_prefix' => env('HONGAYETU_MENSAGENS_WEB_PREFIX', 'mensagens-popup'),
            'route_name_prefix' => 'mensagens-popup.web.',

            // A lista e os eventos. Com outro guard: `'auth:cliente'` aqui e
            // `'guard' => 'cliente'` mais abaixo. O `throttle` sempre com nome.
            'middleware' => [
                'web',
                'auth',
                'throttle:60,1,mensagens-popup-web',
            ],

            // O `popup.js` é público e igual para todos: sem sessão nem `auth`.
            'script_middleware' => [
                'throttle:120,1,mensagens-popup-script',
            ],

            // O guard onde a directiva procura quem está autenticado.
            // `null` = o guard por omissão do serviço.
            'guard' => env('HONGAYETU_MENSAGENS_WEB_GUARD'),

            /*
            | Onde a janela pode abrir, e em nome de que serviço. A primeira
            | zona que casar com o domínio e o caminho da página ganha.
            |
            | - `servico`: a chave do serviço na central (`humbi`, `ticketverse`…).
            | - `superficie`: `site`, `painel` ou `portal` (a `app` não é da web).
            | - `caminhos`: os caminhos exactos da página inicial, sem barra
            |   final (`/` é a raiz). A janela só abre neles.
            | - `anfitrioes`: os domínios da zona; vazio = qualquer domínio.
            |
            | [
            |     ['servico' => 'humbi', 'superficie' => 'site', 'caminhos' => ['/'], 'anfitrioes' => ['humbi.ao']],
            |     ['servico' => 'humbi', 'superficie' => 'painel', 'caminhos' => ['/painel'], 'anfitrioes' => ['negocio.humbi.ao']],
            | ],
            */
            'zonas' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feedback (desde a 0.12.0)
    |--------------------------------------------------------------------------
    | O terceiro proxy: o feedback da central Honga Yetu. A app manda o
    | comentário a este servidor e este encaminha-o à central com o token do
    | ConnectedProject (`POST {route_prefix}` e `POST {route_prefix}/capturas`).
    |
    | Ao contrário das mensagens, a pessoa tem de saber se o comentário não
    | seguiu: se a central falhar, responde 503 com o texto a mostrar.
    |
    | Aceita visitantes: por omissão o middleware é só o limite de pedidos. Se
    | houver alguém autenticado no `guard`, a central fica a saber quem é.
    */
    'feedback' => [
        'enabled' => (bool) env('HONGAYETU_FEEDBACK_ENABLED', true),

        'base_url' => env('HONGAYETU_FEEDBACK_BASE_URL', 'https://hongayetu.com/api/v1/servicos/feedback'),
        // Por omissão, o das mensagens e, sem ele, o dos anúncios: é o mesmo ConnectedProject.
        'token' => env('HONGAYETU_FEEDBACK_TOKEN'),
        'timeout' => (int) env('HONGAYETU_FEEDBACK_TIMEOUT', 8),

        'auto_register_routes' => env('HONGAYETU_FEEDBACK_AUTO_ROUTES', true),
        'route_prefix' => env('HONGAYETU_FEEDBACK_PREFIX', 'api/feedback'),
        'route_name_prefix' => 'feedback.',
        // Sem `auth`: o feedback aceita visitantes. Com nome próprio no `throttle`.
        'middleware' => ['throttle:10,1,feedback'],
        // As capturas têm o limite delas. Se acrescentares um middleware acima
        // (`auth:sanctum`, por exemplo), acrescenta-o aqui também.
        'capturas_middleware' => ['throttle:12,1,feedback-capturas'],

        // O guard onde se procura quem está autenticado (API: o `sanctum`,
        // normalmente). `null` = o guard por omissão. Sem ninguém, é um visitante.
        'guard' => env('HONGAYETU_FEEDBACK_GUARD'),

        // No feedback faz sentido identificar sempre quem escreve: quem não tem
        // conta Honga Yetu vai com a conta do serviço (id e nome, como nas
        // mensagens). `enviar_como=0` envia sem conta nenhuma.
        'contas_sem_honga' => (bool) env('HONGAYETU_FEEDBACK_CONTAS_SEM_HONGA', true),

        // Vazia = vale a lista `mensagens.servicos_permitidos`.
        'servicos_permitidos' => [],
        // Usado quando o pedido não traz `servico` (e, sem ele, o das mensagens).
        'servico_por_omissao' => env('HONGAYETU_FEEDBACK_SERVICO'),

        // De que revendedor é o cliente: o nome de uma classe do serviço que
        // implementa `HongaYetu\InternalAdsProxy\Contratos\OrigemDoFeedback`
        // (`origem(Request $request, mixed $utilizador): ?array` com `tipo`, `id`
        // e `nome`). Resolve-se pelo contentor. `null` = sem origem.
        'origem' => null,

        // As capturas de ecrã: a central recebe endereços `https`, por isso o
        // ficheiro fica no disco do serviço (que tem de ser público).
        'disco' => env('HONGAYETU_FEEDBACK_DISCO', 'public'),

        /*
        | Nos sites (sessão web, com CSRF): `POST {route_prefix}` e
        | `POST {route_prefix}/capturas`.
        */
        'web' => [
            'auto_register_routes' => env('HONGAYETU_FEEDBACK_WEB_AUTO_ROUTES', true),
            'route_prefix' => env('HONGAYETU_FEEDBACK_WEB_PREFIX', 'feedback'),
            'route_name_prefix' => 'feedback.web.',
            'middleware' => ['web', 'throttle:10,1,feedback-web'],
            'capturas_middleware' => ['web', 'throttle:12,1,feedback-capturas-web'],
            'guard' => env('HONGAYETU_FEEDBACK_WEB_GUARD'),
        ],
    ],
];

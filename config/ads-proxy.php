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

        // Vazia = aceita o `servico` que a app mandar. Preenchida, um
        // `servico` fora dela recebe a lista vazia sem se chamar a central.
        'servicos_permitidos' => [],

        // Usado quando o pedido não traz `servico`.
        'servico_por_omissao' => env('HONGAYETU_MENSAGENS_SERVICO'),
    ],
];

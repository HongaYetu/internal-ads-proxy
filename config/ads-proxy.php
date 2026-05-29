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
        'throttle:60,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logs
    |--------------------------------------------------------------------------
    | Canal Laravel para warnings de falhas upstream.
    */
    'log_channel' => env('HONGAYETU_ADS_LOG_CHANNEL', null),
];

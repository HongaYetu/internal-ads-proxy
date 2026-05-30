# Changelog

Todas as alterações relevantes deste package estão documentadas aqui.

O formato segue [Keep a Changelog](https://keepachangelog.com/) e o versionamento segue [SemVer](https://semver.org/) — com a ressalva (típica de packages `internal-*`) que pode haver breaking changes em releases minor se sincronizados com a central.

## [0.6.0] — 2026-05-30

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

# Changelog

Todas as alterações relevantes deste package estão documentadas aqui.

O formato segue [Keep a Changelog](https://keepachangelog.com/) e o versionamento segue [SemVer](https://semver.org/) — com a ressalva (típica de packages `internal-*`) que pode haver breaking changes em releases minor se sincronizados com a central.

## [0.1.0] — 2026-05-29

### Adicionado
- Primeira versão pública.
- `AdsProxyServiceProvider` com auto-discovery Laravel — basta `composer require` e tudo fica registado.
- `AdsProxyController` com 3 endpoints: `POST /serve`, `POST /impression`, `POST /click` (auto-registados em `api/ads-proxy/*` por defeito).
- `AdsProxyClient` HTTP service injectável — útil para chamar a API server-side directamente (ex: render Inertia/Blade).
- Config publishable via `php artisan vendor:publish --tag=hongayetu-ads-proxy-config`.
- Variáveis de env: `HONGAYETU_ADS_BASE_URL`, `HONGAYETU_ADS_TOKEN`, `HONGAYETU_ADS_TIMEOUT`, `HONGAYETU_ADS_PROXY_PREFIX`, `HONGAYETU_ADS_PROXY_AUTO_ROUTES`, `HONGAYETU_ADS_LOG_CHANNEL`.
- Suporte a Laravel 10, 11, 12.

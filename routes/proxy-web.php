<?php

use HongaYetu\InternalAdsProxy\Http\Controllers\AdsProxyController;
use HongaYetu\InternalAdsProxy\Http\Controllers\InteressesController;
use Illuminate\Support\Facades\Route;

// Os anúncios nos sites (sessão web, com cookie e CSRF): os mesmos controladores das rotas da API, num
// prefixo fora de `api/`. É a sessão que diz quem está a ver, e é por ela que a conta Honga Yetu segue
// para a central. Nenhum endereço acaba em `.js` (lição da 0.11.1).
Route::post('/serve', [AdsProxyController::class, 'serve'])->name('serve');
Route::post('/impression', [AdsProxyController::class, 'impression'])->name('impression');
Route::post('/click', [AdsProxyController::class, 'click'])->name('click');
Route::post('/video-event', [AdsProxyController::class, 'videoEvent'])->name('video-event');
Route::post('/interesses', [InteressesController::class, 'registar'])->name('interesses');

// O clique por GET, para o `<a href>` do SDK web: regista e redirige para o anunciante.
Route::get('/click/{token}', [AdsProxyController::class, 'clickRedirect'])->name('click.redirect')->where('token', '[A-Za-z0-9._\-]+');

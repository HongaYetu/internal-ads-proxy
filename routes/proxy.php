<?php

use HongaYetu\InternalAdsProxy\Http\Controllers\AdsProxyController;
use Illuminate\Support\Facades\Route;

Route::post('/serve', [AdsProxyController::class, 'serve'])->name('serve');
Route::post('/impression', [AdsProxyController::class, 'impression'])->name('impression');
Route::post('/click', [AdsProxyController::class, 'click'])->name('click');

// GET click → regista server-side e devolve 302 para o destino. Permite usar
// `<a href>` nativo no SDK web (target=_blank, middle-click, etc) em vez de
// JS POST + redirect — mais robusto contra adblockers e nav-races.
Route::get('/click/{token}', [AdsProxyController::class, 'clickRedirect'])->name('click.redirect')->where('token', '[A-Za-z0-9._\-]+');

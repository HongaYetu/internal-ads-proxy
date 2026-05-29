<?php

use HongaYetu\InternalAdsProxy\Http\Controllers\AdsProxyController;
use Illuminate\Support\Facades\Route;

Route::post('/serve', [AdsProxyController::class, 'serve'])->name('serve');
Route::post('/impression', [AdsProxyController::class, 'impression'])->name('impression');
Route::post('/click', [AdsProxyController::class, 'click'])->name('click');

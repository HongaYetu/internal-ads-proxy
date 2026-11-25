<?php

use HongaYetu\InternalAdsProxy\Http\Controllers\MensagensPopupController;
use Illuminate\Support\Facades\Route;

// As mensagens nos sites (sessão web): é o `popup.js` que as pede. O script em si regista-se no
// ServiceProvider, fora deste grupo, porque não leva sessão nem `auth`.
Route::get('/', [MensagensPopupController::class, 'indexWeb'])->name('index');
Route::post('/{mensagem}/eventos', [MensagensPopupController::class, 'eventoWeb'])->name('eventos')->whereNumber('mensagem');

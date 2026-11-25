<?php

use HongaYetu\InternalAdsProxy\Http\Controllers\MensagensPopupController;
use Illuminate\Support\Facades\Route;

// Os mesmos caminhos da API pública da central, para a app só mudar o endereço base.
Route::get('/', [MensagensPopupController::class, 'index'])->name('index');
Route::post('/{mensagem}/eventos', [MensagensPopupController::class, 'evento'])->name('eventos')->whereNumber('mensagem');

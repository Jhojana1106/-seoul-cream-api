<?php

use Illuminate\Support\Facades\Route;

Route::get('/{path?}', function () {
    $index = public_path('index.html');
    abort_unless(is_file($index), 404);

    return response()->file($index, ['Content-Type' => 'text/html; charset=UTF-8']);
})->where('path', '^(?!api(?:/|$)|up(?:/|$)|storage(?:/|$)).*');

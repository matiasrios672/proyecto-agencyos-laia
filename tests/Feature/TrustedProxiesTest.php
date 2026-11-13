<?php

use Illuminate\Support\Facades\Route;

// Detrás del proxy de Coolify el TLS se termina afuera: la app debe respetar X-Forwarded-* para generar URLs https.
it('genera URLs https y firma bien cuando el proxy envía X-Forwarded-Proto', function () {
    Route::get('/_proxy-probe', fn () => response()->json([
        'secure' => request()->isSecure(),
        'url' => route('login'),
        'signed' => URL::signedRoute('login'),
    ]));

    $json = $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'agency.example.com'])
        ->get('/_proxy-probe')->assertOk()->json();

    expect($json['secure'])->toBeTrue()
        ->and($json['url'])->toStartWith('https://')
        ->and($json['signed'])->toStartWith('https://');
});

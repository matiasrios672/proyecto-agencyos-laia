<?php

use App\Enums\Currency;
use App\Support\Money;

it('formatea con el estilo argentino', function () {
    expect(Money::format(0))->toBe('$ 0,00')
        ->and(Money::format(123_456_789))->toBe('$ 1.234.567,89')
        ->and(Money::format(65_000, Currency::USD))->toBe('US$ 650,00')
        ->and(Money::format(5, 'ARS'))->toBe('$ 0,05');
});

it('convierte a centavos sin errores de punto flotante', function (string|int|float $amount, int $cents) {
    expect(Money::toCents($amount))->toBe($cents);
})->with([
    ['1500.50', 150_050],
    [0.1 + 0.2, 30],
    ['19.99', 1_999],
    [280000, 28_000_000],
    ['0.005', 1],
]);

it('convierte centavos a decimal de formulario', function () {
    expect(Money::toDecimal(150_050))->toBe('1500.50')->and(Money::toDecimal(5))->toBe('0.05');
});

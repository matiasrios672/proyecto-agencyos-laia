<?php

use App\Rules\Cuit;

it('valida CUITs reales con y sin guiones', function (string $cuit) {
    expect(Cuit::isValid($cuit))->toBeTrue();
})->with(['30-71458963-2', '30714589632', '20-12345678-6', '27-20123456-0']);

it('rechaza CUITs inválidos', function (string $cuit) {
    expect(Cuit::isValid($cuit))->toBeFalse();
})->with(['30-71458963-9', '1234', 'abc', '20-12345678-0', '27-20123456-4', '']);

it('normaliza al formato XX-XXXXXXXX-X', function () {
    expect(Cuit::normalize('30714589632'))->toBe('30-71458963-2')
        ->and(Cuit::normalize(''))->toBeNull()
        ->and(Cuit::normalize(null))->toBeNull();
});

<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\TaxCondition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<\App\Models\Client> */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_name' => fake()->company(),
            'trade_name' => fake()->boolean(60) ? fake()->words(2, true) : null,
            'tax_id' => $this->validCuit(),
            'tax_condition' => fake()->randomElement(TaxCondition::cases()),
            'contact_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('+54 9 11 ####-####'),
            'whatsapp' => fake()->numerify('54911########'),
            'city' => fake()->randomElement(['Buenos Aires', 'Córdoba', 'Rosario', 'Mendoza', 'La Plata']),
            'account_status' => AccountStatus::AlDia,
            'is_active' => true,
        ];
    }

    /** CUIT con dígito verificador correcto. */
    private function validCuit(): string
    {
        do {
            $base = fake()->randomElement(['20', '23', '27', '30', '33']).fake()->numerify('########');
            $sum = 0;
            foreach ([5, 4, 3, 2, 7, 6, 5, 4, 3, 2] as $i => $w) {
                $sum += (int) $base[$i] * $w;
            }
            $check = 11 - ($sum % 11);
            $check = $check === 11 ? 0 : $check;
        } while ($check === 10);

        return substr($base, 0, 2).'-'.substr($base, 2).'-'.$check;
    }

    public function status(AccountStatus $status): static
    {
        return $this->state(['account_status' => $status]);
    }
}

<?php

namespace Database\Factories;

use App\Models\FibQrPaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FibQrPaymentAttempt>
 */
class FibQrPaymentAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => fake()->uuid(),
            'expected_amount' => 1000,
            'qr_code' => 'data:image/png;base64,aGVsbG8=',
            'valid_until' => now()->addMinutes(10),
        ];
    }
}

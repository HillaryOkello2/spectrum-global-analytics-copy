<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        $subscription = Subscription::factory();

        return [
            'user_id' => User::factory(),
            'subscription_id' => $subscription,
            // Signups and renewals are billed against the subscription; an
            // upgrade is billed against the target tier instead.
            'payable_type' => Subscription::class,
            'payable_id' => $subscription,
            'method' => PaymentMethod::Mpesa,
            'amount' => fake()->randomFloat(2, 10, 200),
            'currency' => 'USD',
            'status' => PaymentStatus::Pending,
            'gateway' => 'fake',
            'gateway_ref' => 'FAKE-'.strtoupper(fake()->bothify('????####')),
            'idempotency_key' => (string) Str::uuid(),
            'paid_at' => null,
        ];
    }

    public function successful(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Successful,
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Failed,
        ]);
    }
}

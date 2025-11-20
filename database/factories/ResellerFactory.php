<?php

namespace Database\Factories;

use App\Models\Reseller;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResellerFactory extends Factory
{
    protected $model = Reseller::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => $this->faker->randomElement(['plan', 'traffic', 'wallet']),
            'status' => 'active',
            'username_prefix' => null,
            'traffic_total_bytes' => null,
            'traffic_used_bytes' => 0,
            'wallet_balance' => 0,
            'wallet_price_per_gb' => null,
            'window_starts_at' => null,
            'window_ends_at' => null,
            'marzneshin_allowed_service_ids' => null,
            'settings' => null,
        ];
    }

    public function planBased(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'plan',
        ]);
    }

    public function trafficBased(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'traffic',
            'traffic_total_bytes' => 100 * 1024 * 1024 * 1024,
            'window_starts_at' => now(),
            'window_ends_at' => now()->addDays(30),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }

    public function walletBased(): static
    {
        return $this->state(function (array $attributes) {
            // At this point, $attributes contains only the base definition
            // We won't know the final wallet_balance yet
            // So we return a default that will be computed after merge
            $defaultBalance = 10000;
            $firstTopupMin = config('billing.reseller.first_topup.wallet_min', 150000);
            
            return [
                'type' => 'wallet',
                'wallet_balance' => $defaultBalance,
                'wallet_price_per_gb' => null,
                // Default to suspended_wallet for safety
                'status' => $defaultBalance >= $firstTopupMin ? 'active' : 'suspended_wallet',
            ];
        })->afterMaking(function (Reseller $reseller) {
            // After making (with all attributes merged), check the actual balance
            $firstTopupMin = config('billing.reseller.first_topup.wallet_min', 150000);
            
            // Only set status if it wasn't explicitly set to something else
            if ($reseller->status === 'active' || $reseller->status === 'suspended_wallet') {
                $reseller->status = $reseller->wallet_balance >= $firstTopupMin ? 'active' : 'suspended_wallet';
            }
        });
    }

    public function suspendedWallet(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'wallet',
            'status' => 'suspended_wallet',
            'wallet_balance' => -2000,
        ]);
    }
}

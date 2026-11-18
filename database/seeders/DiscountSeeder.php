<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Concerns\DemoDataOnly;
use Illuminate\Database\Seeder;
use Shopper\Core\Enum\DiscountApplyTo;
use Shopper\Core\Enum\DiscountEligibility;
use Shopper\Core\Enum\DiscountRequirement;
use Shopper\Core\Enum\DiscountType;
use Shopper\Core\Models\Discount;

final class DiscountSeeder extends Seeder
{
    use DemoDataOnly;

    public function run(): void
    {
        if ($this->refuseInProduction()) {
            return;
        }

        $this->command?->warn(PHP_EOL.'Creating demo discount...');

        Discount::query()->updateOrCreate(
            ['code' => 'OCEAN10'],
            [
                'is_active' => true,
                'type' => DiscountType::Percentage,
                'value' => 10,
                'apply_to' => DiscountApplyTo::Order->value,
                'min_required' => DiscountRequirement::None->value,
                'min_required_value' => null,
                'eligibility' => DiscountEligibility::Everyone->value,
                'usage_limit' => null,
                'usage_limit_per_user' => false,
                'total_use' => 0,
                'start_at' => now()->subDay(),
                'end_at' => now()->addYear(),
            ],
        );

        $this->command?->info('Discount OCEAN10 (10%) created.');
    }
}

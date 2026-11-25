<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Payment\PaymentMethodCatalog;
use Illuminate\Database\Seeder;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Zone;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Seeders contributed by payment provider modules (see extend()).
     *
     * @var list<class-string<Seeder>>
     */
    private static array $providerSeeders = [];

    /**
     * Let a payment provider module seed its own methods. Called from the
     * provider's service provider, so removing the module removes its rows
     * from fresh seeds.
     *
     * @param  class-string<Seeder>  $seeder
     */
    public static function extend(string $seeder): void
    {
        if (! in_array($seeder, self::$providerSeeders, true)) {
            self::$providerSeeders[] = $seeder;
        }
    }

    /**
     * Forget the registered provider seeders (a fresh app instance without
     * a module, e.g. tests or long-running workers).
     */
    public static function flushState(): void
    {
        self::$providerSeeders = [];
    }

    public function run(): void
    {
        $this->command->warn(PHP_EOL.'Creating payment methods...');

        // Shopper's own manual driver is the keyless path: a fresh store always
        // has a method customers can use.
        $cod = self::seedMethod('cod', [
            'title' => 'Bayar di tempat (COD)',
            'description' => 'Bayar di tempat saat barang diterima.',
            'driver' => 'manual',
        ], enabled: true);

        foreach (self::$providerSeeders as $seeder) {
            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }

        // Once a configured provider enabled prepaid methods, prepaid is the
        // production path and COD stays available for ops but off. Only for a
        // COD row created by this run: a re-seed keeps staff's choice.
        if ($cod->wasRecentlyCreated) {
            // Only the gateway the store sells through counts
            // (App\Support\ActiveProvider): a keyed gateway standing by
            // offers nothing at checkout.
            $catalog = new PaymentMethodCatalog;
            $prepaid = PaymentMethod::query()
                ->where('is_enabled', true)
                ->where('driver', '!=', 'manual')
                ->pluck('driver')
                ->unique()
                ->contains(static fn (mixed $driver): bool => is_string($driver) && $catalog->driverReady($driver));

            $cod->forceFill(['is_enabled' => ! $prepaid])->save();
        }

        // Keep Stripe available but off by default.
        self::seedMethod('stripe', [
            'title' => 'Stripe',
            'description' => 'Kartu kredit/debit via Stripe (opsional).',
            'driver' => 'stripe',
        ], enabled: false);

        // Attach all enabled payment methods to existing zones
        $enabledIds = PaymentMethod::query()->where('is_enabled', true)->pluck('id');
        foreach (Zone::all() as $zone) {
            $zone->paymentMethods()->syncWithoutDetaching($enabledIds);
        }

        $this->command->info('Payment methods created successfully.');
    }

    /**
     * Create or refresh a seeded payment method. `is_enabled` is set only when
     * the row is created: on an existing row it is staff's choice (cpanel),
     * so re-seeding never switches a method on or off. Provider seeders use
     * this too.
     *
     * @param  array<string, mixed>  $attributes  Everything but is_enabled
     */
    public static function seedMethod(string $slug, array $attributes, bool $enabled): PaymentMethod
    {
        $method = PaymentMethod::query()->firstOrNew(['slug' => $slug]);
        $method->fill($attributes);

        if (! $method->exists) {
            $method->is_enabled = $enabled;
        }

        $method->save();

        return $method;
    }
}

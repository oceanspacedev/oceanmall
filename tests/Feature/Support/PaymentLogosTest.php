<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Support\PaymentLogos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Shopper\Core\Models\PaymentMethod;
use Tests\TestCase;

final class PaymentLogosTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata_wins_then_channel_code_then_slug(): void
    {
        $logos = new PaymentLogos;

        $this->assertSame(
            'https://cdn.example.test/pay.png',
            $logos->forMethod(new PaymentMethod(['slug' => 'cod', 'metadata' => ['logo_url' => 'https://cdn.example.test/pay.png']])),
        );
        $this->assertStringContainsString(
            'images/payments/bri.svg',
            (string) $logos->forMethod(new PaymentMethod(['slug' => 'acme-va-bri', 'metadata' => ['channel_code' => 'BRI']])),
        );
        $this->assertStringContainsString('images/payments/cod.svg', (string) $logos->forMethod(new PaymentMethod(['slug' => 'cod'])));
    }

    public function test_unknown_methods_have_no_logo_until_a_provider_source_answers(): void
    {
        $logos = new PaymentLogos;
        $method = new PaymentMethod(['slug' => 'acme-wallet', 'driver' => 'acme']);

        $this->assertNull($logos->forMethod($method));

        $logos->extend(static fn (PaymentMethod $method): ?string => $method->driver === 'acme' ? 'qris' : null);
        $this->assertStringContainsString('images/payments/qris.svg', (string) $logos->forMethod($method));

        $remote = new PaymentLogos;
        $remote->extend(static fn (): string => 'https://cdn.acme.test/wallet.svg');
        $this->assertSame('https://cdn.acme.test/wallet.svg', $remote->forMethod($method));
    }

    public function test_shopper_payment_logo_binding_uses_the_resolver(): void
    {
        $resolver = app('shopper.payment.logo');

        $this->assertStringContainsString('images/payments/cod.svg', (string) $resolver(new PaymentMethod(['slug' => 'cod'])));
    }
}

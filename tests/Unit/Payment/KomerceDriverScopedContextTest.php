<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Support\KomercePaymentLookupContext;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Shopper\Payment\Facades\Payment;
use Tests\TestCase;

/**
 * Shopper's PaymentManager memoizes driver instances; the lookup context is
 * scoped and flushed before every queued job / Octane request.
 */
final class KomerceDriverScopedContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('komerce.payment_api_key', 'test-payment-key');
        config()->set('komerce.payment_base_url', 'https://payment.example.test/user');
        config()->set('komerce.qrisly_api_key', 'test-qrisly-key');
        config()->set('komerce.qrisly_qris_id', '77');
        config()->set('komerce.qrisly_base_url', 'https://qrisly.example.test');
    }

    public function test_retrieve_payment_reads_lookup_context_after_scoped_flush(): void
    {
        Http::fake([
            'https://qrisly.example.test/*' => Http::response([
                'meta' => ['code' => 200, 'status' => 'success'],
                'data' => ['payment_status' => 'paid', 'final_amount' => 150000],
            ]),
            'https://payment.example.test/*' => Http::response(['data' => ['status' => 'UNPAID']]),
        ]);

        $driver = Payment::driver('komerce');

        app()->forgetScopedInstances();
        resolve(KomercePaymentLookupContext::class)->setProvider('qrisly');

        $result = Payment::driver('komerce')->retrievePayment('HIST-1');

        $this->assertSame($driver, Payment::driver('komerce'));
        $this->assertSame('qrisly', $result->data['provider']);
        $this->assertSame('captured', $result->status);
        Http::assertSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://qrisly.example.test/'));
        Http::assertNotSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'https://payment.example.test/'));
    }
}

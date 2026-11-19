<?php

declare(strict_types=1);

namespace Tests\Feature\Midtrans;

use App\Integrations\Midtrans\Exceptions\MidtransNotConfiguredException;
use App\Integrations\Midtrans\Midtrans;
use App\Integrations\Midtrans\Services\MidtransClient;
use App\Integrations\Midtrans\Support\MidtransSignature;
use App\Integrations\Midtrans\Support\MidtransTransactionStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Shopper\Payment\Facades\Payment;
use Tests\TestCase;

/**
 * The host choice is explicit and must agree with the key, so a live
 * checkout never goes to the sandbox (or the other way round).
 */
final class MidtransConfigurationTest extends TestCase
{
    use InteractsWithMidtrans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMidtrans();
    }

    public function test_a_sandbox_key_in_sandbox_mode_is_configured_with_sandbox_hosts(): void
    {
        $this->withMidtrans();

        $this->assertTrue(Midtrans::configured());
        $this->assertTrue(Payment::driver('midtrans')->isConfigured());
        $this->assertSame('https://app.sandbox.midtrans.com', Midtrans::snapBaseUrl());
        $this->assertSame('https://api.sandbox.midtrans.com', Midtrans::apiBaseUrl());
    }

    public function test_production_mode_uses_production_hosts(): void
    {
        config()->set('midtrans.server_key', 'Mid-server-live');
        config()->set('midtrans.is_production', true);

        $this->assertTrue(Midtrans::configured());
        $this->assertSame('https://app.midtrans.com', Midtrans::snapBaseUrl());
        $this->assertSame('https://api.midtrans.com', Midtrans::apiBaseUrl());
    }

    public function test_an_unset_production_flag_outside_production_means_sandbox(): void
    {
        config()->set('midtrans.server_key', self::SERVER_KEY);

        $this->assertTrue(Midtrans::configured());
        $this->assertSame('sandbox', Midtrans::environment());
    }

    public function test_app_env_production_requires_an_explicit_flag(): void
    {
        config()->set('midtrans.server_key', 'Mid-server-live');
        $this->app['env'] = 'production';

        try {
            $this->assertFalse(Midtrans::configured());
            $this->assertStringContainsString('MIDTRANS_IS_PRODUCTION', (string) Midtrans::configurationError());

            config()->set('midtrans.is_production', true);
            $this->assertTrue(Midtrans::configured());
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_key_prefixes_that_contradict_the_host_are_refused(): void
    {
        config()->set('midtrans.server_key', 'SB-Mid-server-abc');
        config()->set('midtrans.is_production', true);
        $this->assertFalse(Midtrans::configured(), 'Sandbox key against production hosts.');
        $this->assertTrue(Midtrans::hasKey());

        // Midtrans does not guarantee the SB- prefix for sandbox keys, so a
        // Mid-server- key in (explicit) sandbox mode is accepted.
        config()->set('midtrans.server_key', 'Mid-server-abc');
        config()->set('midtrans.is_production', false);
        $this->assertTrue(Midtrans::configured(), 'Unprefixed sandbox keys exist.');

        config()->set('midtrans.server_key', 'VT-server-legacy');
        $this->assertTrue(Midtrans::configured(), 'Unknown prefixes are not guessed at.');
    }

    public function test_the_client_sends_nothing_while_not_configured(): void
    {
        Http::fake();
        config()->set('midtrans.server_key', 'SB-Mid-server-abc');
        config()->set('midtrans.is_production', true);

        try {
            resolve(MidtransClient::class)->status('ORD-1');
            $this->fail('Expected MidtransNotConfiguredException.');
        } catch (MidtransNotConfiguredException $e) {
            $this->assertStringContainsString('sandbox', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_requests_use_basic_auth_with_the_server_key(): void
    {
        $this->withMidtrans();
        Http::fake([self::API.'*' => Http::response($this->statusBody('ORD-1', 'pending'))]);

        resolve(MidtransClient::class)->status('ORD-1');

        Http::assertSent(fn (Request $request): bool => $request->url() === self::API.'ORD-1/status'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::SERVER_KEY.':')));
    }

    public function test_signature_is_sha512_of_order_status_amount_and_key(): void
    {
        $expected = hash('sha512', 'ORD-1'.'200'.'150000.00'.self::SERVER_KEY);

        $this->assertSame($expected, MidtransSignature::expected('ORD-1', '200', '150000.00', self::SERVER_KEY));
        $this->assertTrue(MidtransSignature::isValid('ORD-1', '200', '150000.00', self::SERVER_KEY, strtoupper($expected)));
        $this->assertFalse(MidtransSignature::isValid('ORD-1', '200', '150000', self::SERVER_KEY, $expected));
        $this->assertFalse(MidtransSignature::isValid('ORD-1', '200', '150000.00', '', $expected));
    }

    public function test_statuses_and_amounts_are_normalized(): void
    {
        $state = static fn (array $body): string => MidtransTransactionStatus::fromResponse($body)->state;

        $this->assertSame('paid', $state(['status_code' => '200', 'transaction_status' => 'settlement']));
        $this->assertSame('paid', $state(['status_code' => '200', 'transaction_status' => 'capture', 'fraud_status' => 'accept']));
        $this->assertSame('pending', $state(['status_code' => '201', 'transaction_status' => 'capture', 'fraud_status' => 'challenge']));
        $this->assertSame('failed', $state(['status_code' => '200', 'transaction_status' => 'capture', 'fraud_status' => 'deny']));
        $this->assertSame('pending', $state(['status_code' => '201', 'transaction_status' => 'pending']));
        $this->assertSame('failed', $state(['status_code' => '407', 'transaction_status' => 'expire']));
        $this->assertSame('failed', $state(['status_code' => '202', 'transaction_status' => 'deny']));
        $this->assertSame('canceled', $state(['status_code' => '200', 'transaction_status' => 'cancel']));
        $this->assertSame('refunded', $state(['status_code' => '200', 'transaction_status' => 'refund']));
        $this->assertSame('not_found', $state(['status_code' => '404', 'status_message' => "Transaction doesn't exist."]));
        $this->assertSame('unknown', $state(['status_code' => '200', 'transaction_status' => 'something_new']));

        $this->assertSame(150000, MidtransTransactionStatus::amount('150000.00'));
        $this->assertSame(150000, MidtransTransactionStatus::amount('150000'));
        $this->assertSame(-1, MidtransTransactionStatus::amount('150000.50'));
        $this->assertNull(MidtransTransactionStatus::amount('abc'));
    }
}

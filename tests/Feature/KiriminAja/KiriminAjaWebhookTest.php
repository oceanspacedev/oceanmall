<?php

declare(strict_types=1);

namespace Tests\Feature\KiriminAja;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * KiriminAja callbacks: authenticated by the account's Bearer key (or the
 * optional URL token), applied only after the Tracking API confirms the
 * state, and idempotent.
 */
final class KiriminAjaWebhookTest extends TestCase
{
    use InteractsWithKiriminAja;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureKiriminAja();
        Http::preventStrayRequests();
    }

    public function test_a_signed_callback_is_verified_through_the_tracking_api(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::response(self::trackingResponse('OM1-TEST', 'JNE0001', 105))]);
        [, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST']);

        $this->withToken(self::API_KEY)
            ->postJson('/webhooks/kiriminaja', $this->payload('processed_packages', 'OM1-TEST', 'JNE0001'))
            ->assertOk()
            ->assertJson(['status' => 'handled', 'handled' => 1]);

        $shipment->refresh();
        $this->assertSame('JNE0001', $shipment->awb);
        $this->assertSame('labeled', $shipment->status);
        $this->assertSame('processed_packages', data_get($shipment->metadata, 'kiriminaja.callback_method'));
        Http::assertSent(fn (Request $request): bool => $request->data() === ['order_id' => 'OM1-TEST']);
    }

    public function test_the_awb_is_taken_from_the_api_never_from_the_body(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::response(self::trackingResponse('OM1-TEST', null, 101))]);
        [, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST']);

        $this->withToken(self::API_KEY)
            ->postJson('/webhooks/kiriminaja', $this->payload('finished_packages', 'OM1-TEST', 'FORGED-AWB'))
            ->assertOk();

        $shipment->refresh();
        $this->assertNull($shipment->awb);
        $this->assertSame('pending', $shipment->status);
    }

    public function test_a_wrong_or_missing_key_is_rejected_without_any_call(): void
    {
        Http::fake();
        [, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST']);

        $this->withToken('wrong-key')
            ->postJson('/webhooks/kiriminaja', $this->payload('finished_packages', 'OM1-TEST', 'X'))
            ->assertUnauthorized();
        $this->postJson('/webhooks/kiriminaja?token=guess', $this->payload('finished_packages', 'OM1-TEST', 'X'))
            ->assertUnauthorized();

        $this->assertNull($shipment->refresh()->awb);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'tracking'));
    }

    public function test_the_url_token_is_accepted_when_a_secret_is_configured(): void
    {
        config()->set('kiriminaja.webhook_secret', 's3cret-token');
        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::response(self::trackingResponse('OM1-TEST', 'JNE0001', 105))]);
        [, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST']);

        $this->postJson('/webhooks/kiriminaja?token=s3cret-token', $this->payload('processed_packages', 'OM1-TEST', 'JNE0001'))
            ->assertOk();

        $this->assertSame('JNE0001', $shipment->refresh()->awb);
    }

    public function test_duplicate_deliveries_are_applied_once(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::response(self::trackingResponse('OM1-TEST', 'JNE0001', 105))]);
        $this->paidShipment(['external_reference' => 'OM1-TEST']);
        $payload = $this->payload('processed_packages', 'OM1-TEST', 'JNE0001');

        $this->withToken(self::API_KEY)->postJson('/webhooks/kiriminaja', $payload)->assertOk();
        $this->withToken(self::API_KEY)->postJson('/webhooks/kiriminaja', $payload)->assertOk()->assertJson(['handled' => 1]);

        $this->assertSame(1, Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'tracking'))->count());
    }

    public function test_a_later_event_for_the_same_parcel_is_processed(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::sequence()
            ->push(self::trackingResponse('OM1-TEST', 'JNE0001', 105))
            ->push(self::trackingResponse('OM1-TEST', 'JNE0001', 200, delivered: true, shippedAt: '2026-11-19 17:44:04')),
        ]);
        [, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST']);

        $this->withToken(self::API_KEY)->postJson('/webhooks/kiriminaja', $this->payload('processed_packages', 'OM1-TEST', 'JNE0001'))->assertOk();
        $this->withToken(self::API_KEY)->postJson('/webhooks/kiriminaja', $this->payload('finished_packages', 'OM1-TEST', 'JNE0001', finishedAt: '2026-11-20T09:00:00Z'))->assertOk();

        $this->assertSame('delivered', $shipment->refresh()->status);
    }

    public function test_unknown_parcels_are_ignored(): void
    {
        Http::fake();

        $this->withToken(self::API_KEY)
            ->postJson('/webhooks/kiriminaja', $this->payload('processed_packages', 'OTHER-SHOP-1', 'X'))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        Http::assertNothingSent();
    }

    public function test_a_failed_verification_asks_kiriminaja_to_retry(): void
    {
        Http::fake([self::BASE_URL.'/api/mitra/tracking' => Http::sequence()
            ->push('Bad gateway', 502)
            ->push(self::trackingResponse('OM1-TEST', 'JNE0001', 105)),
        ]);
        [, $shipment] = $this->paidShipment(['external_reference' => 'OM1-TEST']);
        $payload = $this->payload('processed_packages', 'OM1-TEST', 'JNE0001');

        $this->withToken(self::API_KEY)->postJson('/webhooks/kiriminaja', $payload)->assertStatus(500);
        $this->assertNull($shipment->refresh()->awb);

        // Not remembered as handled: the retry is processed.
        $this->withToken(self::API_KEY)->postJson('/webhooks/kiriminaja', $payload)->assertOk();
        $this->assertSame('JNE0001', $shipment->refresh()->awb);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $method, string $orderId, string $awb, ?string $finishedAt = null): array
    {
        return [
            'method' => $method,
            'data' => [[
                'order_id' => $orderId,
                'awb' => $awb,
                'date' => '2026-11-19T06:02:03.000000Z',
                'shipped_at' => null,
                'finished_at' => $finishedAt,
                'returned_at' => null,
                'rejected_at' => null,
                'reason' => null,
            ]],
            'payment' => ['payment_id' => 'XID-1', 'amount' => 12000, 'status_code' => 0],
            'packages' => [['awb' => $awb, 'order_id' => $orderId, 'service' => 'jne', 'status' => 105]],
        ];
    }
}

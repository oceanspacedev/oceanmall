<?php

declare(strict_types=1);

namespace Tests\Feature\Biteship;

use App\Models\OrderShipment;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class BiteshipWebhookTest extends BiteshipTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableBiteship();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return [
            'event' => 'order.status',
            'order_id' => 'BS-ORDER-1',
            'courier_tracking_id' => 'BS-TRACK-1',
            'courier_waybill_id' => 'JNE-WB-001',
            'courier_company' => 'jne',
            'courier_type' => 'reg',
            'order_price' => 18000,
            'status' => 'delivered',
            ...$overrides,
        ];
    }

    private function registeredShipment(): OrderShipment
    {
        [, $shipment] = $this->paidShipment([
            'external_reference' => 'BS-ORDER-1',
            'status' => 'labeled',
            'metadata' => [
                'rate' => ['courier_company' => 'jne', 'courier_type' => 'reg'],
                'biteship' => ['order_id' => 'BS-ORDER-1', 'tracking_id' => 'BS-TRACK-1'],
            ],
        ]);

        return $shipment;
    }

    private function fakeProvider(string $status = 'picked', ?string $waybill = 'JNE-WB-001'): void
    {
        Http::fake([
            self::BASE.'/v1/orders/BS-ORDER-1' => Http::response($this->biteshipOrder($status, $waybill)),
            self::BASE.'/v1/trackings/BS-TRACK-1' => Http::response([
                'success' => true,
                'id' => 'BS-TRACK-1',
                'waybill_id' => $waybill,
                'status' => $status,
                'history' => [['note' => 'Picked up', 'status' => $status, 'updated_at' => '2026-11-19T12:00:00+07:00']],
            ]),
        ]);
    }

    public function test_the_installation_ping_is_answered_200_with_an_empty_body(): void
    {
        Http::fake();

        $this->postJson('/webhooks/biteship', [])->assertOk()->assertContent('');
        $this->call('POST', '/webhooks/biteship')->assertOk();

        Http::assertNothingSent();
    }

    public function test_events_without_the_secret_header_are_refused(): void
    {
        $shipment = $this->registeredShipment();
        Http::fake();

        $this->postJson('/webhooks/biteship', $this->event())->assertUnauthorized();
        $this->postJson('/webhooks/biteship', $this->event(), ['X-Biteship-Secret' => 'wrong'])->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertSame('labeled', $shipment->refresh()->status);
    }

    public function test_events_are_refused_while_no_secret_is_configured(): void
    {
        config()->set('biteship.webhook_secret', '');
        Http::fake();

        $this->postJson('/webhooks/biteship', $this->event(), ['X-Biteship-Secret' => ''])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_the_payload_is_a_signal_only_the_order_is_re_read_from_biteship(): void
    {
        $shipment = $this->registeredShipment();
        // The payload claims "delivered"; Biteship's API says "picked".
        $this->fakeProvider('picked');

        $this->postJson('/webhooks/biteship', $this->event(), ['X-Biteship-Secret' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('status', 'handled');

        $shipment->refresh();
        $this->assertSame('picked_up', $shipment->status);
        $this->assertSame('JNE-WB-001', $shipment->awb);
        $this->assertSame('delivered', data_get($shipment->metadata, 'biteship.webhook.reported_status'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_ends_with($request->url(), '/v1/orders/BS-ORDER-1'));
    }

    public function test_the_secret_header_name_is_configurable(): void
    {
        config()->set('biteship.webhook_header', 'X-Custom-Token');
        $this->registeredShipment();
        $this->fakeProvider();

        $this->postJson('/webhooks/biteship', $this->event(), ['X-Custom-Token' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('status', 'handled');
    }

    public function test_a_waybill_event_stores_the_new_waybill_from_the_api(): void
    {
        $shipment = $this->registeredShipment();
        $this->fakeProvider('picked', 'JNE-WB-NEW');

        $this->postJson('/webhooks/biteship', $this->event(['event' => 'order.waybill_id', 'courier_waybill_id' => 'JNE-WB-NEW', 'status' => 'picked']), ['X-Biteship-Secret' => self::SECRET])
            ->assertOk();

        $this->assertSame('JNE-WB-NEW', $shipment->refresh()->awb);
    }

    public function test_a_duplicate_callback_is_applied_once(): void
    {
        $this->registeredShipment();
        $this->fakeProvider();
        $headers = ['X-Biteship-Secret' => self::SECRET];

        $this->postJson('/webhooks/biteship', $this->event(), $headers)->assertJsonPath('status', 'handled');
        $calls = count(Http::recorded());

        $this->postJson('/webhooks/biteship', $this->event(), $headers)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertCount($calls, Http::recorded(), 'No second provider round-trip');
    }

    public function test_an_unknown_order_is_acknowledged_without_changes(): void
    {
        Http::fake();

        $this->postJson('/webhooks/biteship', $this->event(['order_id' => 'NOT-OURS']), ['X-Biteship-Secret' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('status', 'not_found');

        Http::assertNothingSent();
    }

    public function test_an_unknown_event_is_ignored(): void
    {
        Http::fake();

        $this->postJson('/webhooks/biteship', ['event' => 'something.else', 'order_id' => 'BS-ORDER-1'], ['X-Biteship-Secret' => self::SECRET])
            ->assertOk()
            ->assertJsonPath('status', 'ignored');
    }

    public function test_a_provider_failure_answers_5xx_so_biteship_retries(): void
    {
        $shipment = $this->registeredShipment();
        Http::fake([self::BASE.'/v1/orders/BS-ORDER-1' => Http::response(['success' => false, 'error' => 'boom'], 500)]);

        $this->postJson('/webhooks/biteship', $this->event(), ['X-Biteship-Secret' => self::SECRET])->assertStatus(503);

        $this->assertSame('labeled', $shipment->refresh()->status);
    }
}

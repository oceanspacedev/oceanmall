<?php

declare(strict_types=1);

namespace Tests\Feature\Komerce;

use App\Integrations\Komerce\Exceptions\KomerceNotConfiguredException;
use App\Integrations\Komerce\Jobs\CreateRajaOngkirDeliveryForShipment;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Services\ShippingCostClient;
use App\Integrations\Komerce\Services\ShippingDeliveryClient;
use App\Models\OrderShipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class KomerceDisabledTest extends TestCase
{
    use RefreshDatabase;

    private function disableKomerce(): void
    {
        config()->set('komerce.payment_api_key', '');
        config()->set('komerce.shipping_cost_api_key', '');
        config()->set('komerce.shipping_delivery_api_key', '');
        config()->set('komerce.qrisly_api_key', '');
        config()->set('komerce.qrisly_qris_id', '');
        config()->set('komerce.enabled', null);
    }

    private function admin(): User
    {
        $this->configureShopperCpanel();

        $admin = User::factory()->create();
        Role::query()->firstOrCreate(['name' => config('shopper.admin.roles.admin'), 'guard_name' => 'web']);
        $admin->assignRole(config('shopper.admin.roles.admin'));

        return $admin;
    }

    public function test_komerce_is_disabled_when_no_api_key(): void
    {
        $this->disableKomerce();

        $this->assertFalse(Komerce::enabled());
    }

    public function test_legacy_general_key_does_not_enable_any_service(): void
    {
        config()->set('komerce.enabled', null);
        config()->set('komerce.api_key', 'live-key');

        $this->assertFalse(Komerce::enabled());
        $this->assertFalse(Komerce::paymentEnabled());
        $this->assertFalse(Komerce::shippingCostEnabled());
        $this->assertFalse(Komerce::shippingDeliveryEnabled());
    }

    public function test_komerce_is_enabled_when_only_shipping_cost_key_present(): void
    {
        $this->disableKomerce();
        config()->set('komerce.shipping_cost_api_key', 'shipping-cost-key');

        $this->assertTrue(Komerce::enabled());
    }

    public function test_explicit_disable_flag_overrides_a_present_api_key(): void
    {
        config()->set('komerce.payment_api_key', 'payment-key');
        config()->set('komerce.enabled', false);

        $this->assertFalse(Komerce::enabled());
        $this->assertFalse(Komerce::paymentEnabled());
    }

    public function test_delivery_client_throws_when_disabled(): void
    {
        $this->disableKomerce();
        Http::fake();

        $this->expectException(KomerceNotConfiguredException::class);

        resolve(ShippingDeliveryClient::class)->storeOrder(['order_no' => 'X']);
    }

    public function test_shipping_cost_client_throws_when_disabled(): void
    {
        $this->disableKomerce();
        Http::fake();

        $this->expectException(KomerceNotConfiguredException::class);

        resolve(ShippingCostClient::class)->calculate(['id' => 1], ['id' => 2], 1000, ['jne']);
    }

    public function test_delivery_job_is_a_noop_when_disabled(): void
    {
        $this->disableKomerce();
        Http::fake();

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $inventory = Inventory::factory()->create(['rajaongkir_origin_id' => '501']);
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'pending',
        ]);

        resolve(CreateRajaOngkirDeliveryForShipment::class, ['orderShipmentId' => $shipment->id])
            ->handle();

        Http::assertNothingSent();
        $this->assertNull($shipment->refresh()->awb);
        $this->assertSame('pending', $shipment->status);
    }

    public function test_payment_webhook_acks_official_handled_when_disabled(): void
    {
        $this->disableKomerce();
        config()->set('komerce.webhook_secret', 'webhook-secret');
        Http::fake();

        $payload = [
            'payment_id' => 'KOMPAY-1',
            'status' => 'PAID',
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, 'webhook-secret');

        // Every disabled provider webhook gets the same HTTP 200 `{status: disabled}`
        // acknowledgement (no retries) and no remote payment-status call is attempted.
        $this->call(
            'POST',
            route('webhooks.komerce.payment'),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CALLBACK_API_KEY' => $signature,
            ],
            $body,
        )->assertOk()->assertJson(['status' => 'disabled']);

        Http::assertNothingSent();
    }

    public function test_admin_label_shows_friendly_error_when_disabled(): void
    {
        $this->disableKomerce();
        Http::fake();

        $order = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $inventory = Inventory::factory()->create();
        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'pending',
            'awb' => null,
            'metadata' => ['komerce' => ['order_no' => 'RO-ORDER-1']],
        ]);

        $this->from(route('account.orders.show', $order))
            ->actingAs($this->admin())
            ->get(route('shopper.orders.fulfillment.print-label', $order))
            ->assertSessionHasErrors('label');

        Http::assertNothingSent();
    }

    public function test_customer_tracking_shows_friendly_error_when_disabled(): void
    {
        $this->disableKomerce();
        Http::fake();

        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        $inventory = Inventory::factory()->create();
        $shipment = OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'jne',
            'service_code' => 'REG',
            'status' => 'labeled',
            'awb' => 'JNE-AWB',
        ]);

        $this->from(route('account.orders.show', $order))
            ->actingAs($customer)
            ->post(route('account.orders.shipments.track', ['order' => $order, 'shipment' => $shipment]))
            ->assertRedirect(route('account.orders.show', $order))
            ->assertSessionHasErrors('tracking');

        Http::assertNothingSent();
    }
}

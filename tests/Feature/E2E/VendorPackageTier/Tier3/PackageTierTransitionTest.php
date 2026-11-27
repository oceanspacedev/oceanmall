<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier3;

use App\Models\OrderShipment;
use App\Shipping\ShippingCapabilities;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Core\Enum\AddressType;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Address;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\Order;
use Tests\Feature\E2E\VendorPackageTier\Support\KomerceHttpFakeBuilder;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class PackageTierTransitionTest extends VendorPackageTierTestCase
{
    public function test_starter_to_komship_upgrade_updates_carrier_sync_checkout_driver_and_fulfills_starter_quoted_order(): void
    {
        $this->applyTier('starter');
        $admin = $this->fixtures->createAdmin();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);

        // 1. Starter sync enables Cost-quoted couriers (including pos) and leaves ide disabled
        $this->artisan('shipping:sync-carriers')->assertSuccessful();
        $this->assertTrue((bool) Carrier::query()->where('slug', 'jne')->value('is_enabled'));
        $this->assertTrue((bool) Carrier::query()->where('slug', 'pos')->value('is_enabled'));
        $this->assertFalse((bool) Carrier::query()->where('slug', 'ide')->value('is_enabled'));

        // 2. Customer places Starter-quoted order (driver='rajaongkir', cost=18000) & pays via webhook
        $apiUser = $this->apiCustomerWithCart($product);
        $this->apiSaveShippingAddress($apiUser)->assertOk();
        $this->apiPickShippingOption($apiUser, 'jne:REG')->assertOk();
        $placed = $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['bca']->id)->assertCreated();

        $starterOrder = Order::query()->where('number', $placed->json('data.number'))->firstOrFail();
        $starterShipment = OrderShipment::query()->where('order_id', $starterOrder->id)->firstOrFail();
        $this->assertSame('rajaongkir', $starterShipment->driver);
        $this->assertSame(18000, (int) $starterShipment->cost);

        $this->applyTier('starter')
            ->withPaymentStatus('KOMPAY-VA-1', 'PAID', 118000)
            ->apply();
        $this->postSignedKomercePaymentWebhook([
            'payment_id' => 'KOMPAY-VA-1',
            'order_id' => $starterOrder->number,
            'status' => 'PAID',
            'amount' => 118000,
        ])->assertOk();
        $this->assertSame(PaymentStatus::Paid, $starterOrder->fresh()->payment_status);

        // 3. Upgrade vendor plan mid-flight from Starter -> Komship (add Delivery & QRISLY keys, probe capabilities)
        $this->setVendorTierConfig([
            'shipping_delivery_api_key' => 'delivery-key',
            'qrisly_api_key' => 'qrisly-key',
            'qrisly_qris_id' => '18',
        ]);
        $this->resetTierState(flushCache: false);

        KomerceHttpFakeBuilder::forTier('komship')
            ->withDeliveryTariffs([
                [
                    'shipping_name' => 'JNE',
                    'service_name' => 'REG',
                    'weight' => 1,
                    'is_cod' => true,
                    'shipping_cost' => 18000,
                    'shipping_cashback' => 1000,
                    'shipping_cost_net' => 17000,
                    'grandtotal' => 118000,
                    'service_fee' => 0,
                    'etd' => '2-3 day',
                ],
                [
                    'shipping_name' => 'JNE',
                    'service_name' => 'JNEFlat',
                    'weight' => 1,
                    'is_cod' => true,
                    'shipping_cost' => 12000,
                    'shipping_cashback' => 1000,
                    'shipping_cost_net' => 11000,
                    'grandtotal' => 112000,
                    'service_fee' => 0,
                    'etd' => '1-2 day',
                ],
                [
                    'shipping_name' => 'JNT',
                    'service_name' => 'EZ',
                    'weight' => 1,
                    'is_cod' => true,
                    'shipping_cost' => 11000,
                    'shipping_cashback' => 0,
                    'shipping_cost_net' => 11000,
                    'grandtotal' => 111000,
                    'service_fee' => 0,
                    'etd' => '1-2 day',
                ],
                [
                    'shipping_name' => 'SPX',
                    'service_name' => 'SPXSTD',
                    'weight' => 1,
                    'is_cod' => false,
                    'shipping_cost' => 10500,
                    'shipping_cashback' => 0,
                    'shipping_cost_net' => 10500,
                    'grandtotal' => 110500,
                    'service_fee' => 0,
                    'etd' => '2-3 day',
                ],
                [
                    'shipping_name' => 'IDEXPRESS',
                    'service_name' => 'IDFLAT',
                    'weight' => 1,
                    'is_cod' => false,
                    'shipping_cost' => 10000,
                    'shipping_cashback' => 0,
                    'shipping_cost_net' => 10000,
                    'grandtotal' => 110000,
                    'service_fee' => 0,
                    'etd' => '2-3 day',
                ],
            ])
            ->apply();

        $this->artisan('komerce:capabilities')->assertSuccessful();
        $this->assertTrue($this->komerceProductEnabled('shipping_delivery'));

        // Carrier sync after upgrade enables Delivery couriers (ide) & disables Cost-only pos
        $this->artisan('shipping:sync-carriers')->assertSuccessful();
        $this->assertTrue((bool) Carrier::query()->where('slug', 'ide')->value('is_enabled'));
        $this->assertFalse((bool) Carrier::query()->where('slug', 'pos')->value('is_enabled'));

        // New Web checkout now quotes via 'komerce' Delivery driver
        ['user' => $webUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));
        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deliveryOptions.0.driver', 'komerce')
            );

        // Cpanel fulfills the earlier Starter-quoted order via rateFromDeliveryCalculate()
        $this->cpanelOrderShipping($admin, $starterOrder)
            ->call('processAllDeliveryOrders')
            ->assertSet('overrideError', null);

        $starterShipment->refresh();
        $this->assertSame('RO-E2E-1', $starterShipment->external_reference);
        $this->assertSame('JNE-E2E-1', $starterShipment->awb);
    }

    public function test_delivery_only_allocation_and_komship_to_starter_downgrade_manual_awb_and_cost_tracking(): void
    {
        // 1. Delivery-Only: carrier sync makes zero Cost HTTP calls & migrated rajaongkir origin allocates
        $this->applyTier('delivery_only');
        $admin = $this->fixtures->createAdmin();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);

        $this->artisan('shipping:sync-carriers')->assertSuccessful();
        Http::assertNotSent(fn ($req): bool => str_contains($req->url(), 'cost.example.test'));
        $this->assertTrue((bool) Carrier::query()->where('slug', 'ide')->value('is_enabled'));
        $this->assertFalse((bool) Carrier::query()->where('slug', 'pos')->value('is_enabled'));

        // Web checkout using base fixture's migrated 'rajaongkir' origin must allocate & quote Delivery
        ['user' => $deliveryWebUser] = $this->webCustomerWithCart($product);
        $this->webSaveShippingAddress($deliveryWebUser)->assertRedirect(route('shop.checkout.index'));
        $this->webLoadCheckout($deliveryWebUser, 2)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deliveryOptions.0.driver', 'komerce')
            );

        // 2. Komship -> Starter downgrade: Komship-quoted paid order fulfilled with manual AWB & Cost tracking
        $this->applyTier('komship');
        $customer = $this->fixtures->createVerifiedCustomer();
        ['order' => $order, 'shipment' => $shipment] = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            ['driver' => 'komerce', 'carrier_code' => 'jne', 'service_code' => 'JNEFlat'],
        );

        // Downgrade to Starter (Delivery API removed / unentitled)
        $manifestTime = now()->addMinutes(5);
        $this->applyTier('starter')
            ->withCostTracking([
                'delivered' => false,
                'summary' => ['waybill_number' => 'JNE-DOWNGRADE-1', 'status' => 'ON PROCESS'],
                'delivery_status' => ['status' => 'ON PROCESS'],
                'manifest' => [
                    [
                        'manifest_code' => '100',
                        'manifest_description' => 'Shipment received by JNE',
                        'manifest_date' => $manifestTime->format('Y-m-d'),
                        'manifest_time' => $manifestTime->format('H:i'),
                        'city_name' => 'Cirebon',
                    ],
                ],
            ])
            ->apply();
        $this->assertFalse($this->komerceProductEnabled('shipping_delivery'));
        $this->assertNull(resolve(ShippingCapabilities::class)->fulfillerFor($shipment));

        $this->cpanelOrderFulfillment($admin, $order)
            ->assertSee(__('shopper::pages/orders.create_shipping_label'), false)
            ->assertDontSee('Terbitkan Resi Komerce', false);

        $this->cpanelCreateShippingLabel($admin, $order)
            ->set('data.tracking_number', 'JNE-DOWNGRADE-1')
            ->call('save')
            ->assertHasNoErrors();

        $shipment->refresh();
        $this->assertSame('JNE-DOWNGRADE-1', $shipment->awb);

        // Starter Cpanel shipping card renders accurate status badges & print button (M3 #10)
        $this->cpanelOrderShipping($admin, $order)
            ->assertDontSee('Sebagian Terdaftar', false)
            ->assertSee('Cetak Semua Stiker Resi', false)
            ->assertSee('Cetak Stiker Resi', false);

        // Customer API tracking routes via Cost /api/v1/track/waybill even though shipment.driver === 'komerce'
        $this->apiTrackShipment($customer, $order->number, $shipment->id)
            ->assertOk()
            ->assertJsonPath('tracking_refreshed', true)
            ->assertJsonPath('data.shipments.0.tracking_history.0.description', 'Shipment received by JNE');

        Http::assertSent(fn ($req): bool => str_contains($req->url(), '/api/v1/track/waybill')
            && ($req->data()['awb'] ?? null) === 'JNE-DOWNGRADE-1');

        // 3. Downgraded Komship parcel retaining external_reference (order_no) + awb prints thermal-label 200 (single & batch)
        $komshipOrderNoShipment = $this->fixtures->createOrderWithShipment(
            $customer,
            [],
            [
                'driver' => 'rajaongkir',
                'carrier_code' => 'jne',
                'service_code' => 'REG',
                'external_reference' => 'RO-DOWNGRADE-1',
                'status' => 'labeled',
                'awb' => 'JNE-KOM-DOWNGRADE-1',
                'tracking_number' => 'JNE-KOM-DOWNGRADE-1',
                'metadata' => ['komerce' => ['order_no' => 'RO-DOWNGRADE-1', 'awb' => 'JNE-KOM-DOWNGRADE-1']],
            ],
        );

        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', [
                'order' => $komshipOrderNoShipment['order'],
                'shipment' => $komshipOrderNoShipment['shipment']->id,
            ]))
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('JNE-KOM-DOWNGRADE-1', false);

        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $komshipOrderNoShipment['order']))
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('JNE-KOM-DOWNGRADE-1', false);
    }

    public function test_starter_and_delivery_only_switch_preserves_same_family_saved_address_destination_while_isolating_foreign_providers(): void
    {
        $product = $this->fixtures->createStockedProduct(100000, 10, 500);

        // 1. Customer saves address under Starter (destination_driver = 'rajaongkir')
        $this->applyTier('starter');
        $customer = $this->fixtures->createVerifiedCustomer();

        $rajaAddress = Address::query()->create([
            'user_id' => $customer->id,
            'country_id' => $this->fixtures->country->id,
            'type' => AddressType::Shipping,
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'street_address' => 'Jl. Merdeka No. 1',
            'postal_code' => '10110',
            'city' => 'Jakarta Pusat',
            'state' => 'DKI Jakarta',
            'phone_number' => '081234567890',
            'shipping_default' => true,
            'metadata' => json_encode([
                'destination_id' => '17601',
                'destination_label' => 'GAMBIR, GAMBIR, JAKARTA PUSAT, DKI JAKARTA, 10110',
                'destination_driver' => 'rajaongkir',
                'pin_point' => '-6.1754,106.8272',
            ], JSON_THROW_ON_ERROR),
        ]);

        // Also seed a foreign-provider saved address (e.g. biteship) to verify cross-provider isolation
        Address::query()->create([
            'user_id' => $customer->id,
            'country_id' => $this->fixtures->country->id,
            'type' => AddressType::Shipping,
            'first_name' => 'Siti',
            'last_name' => 'Biteship',
            'street_address' => 'Jl. Foreign No. 9',
            'postal_code' => '10110',
            'city' => 'Jakarta Pusat',
            'state' => 'DKI Jakarta',
            'phone_number' => '081299998888',
            'shipping_default' => false,
            'metadata' => json_encode([
                'destination_id' => 'IDNP6-FOREIGN',
                'destination_label' => 'GAMBIR (BITESHIP)',
                'destination_driver' => 'biteship',
            ], JSON_THROW_ON_ERROR),
        ]);

        // 2. Switch to Delivery-Only (destinationDriverCode = 'komerce', same RajaOngkir family)
        $this->applyTier('delivery_only');

        // Web /account/addresses preserves '17601' and clears 'biteship' foreign id
        $this->actingAs($customer)
            ->get(route('account.addresses'))
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $addresses = collect($page->toArray()['props']['addresses']);
                $raja = $addresses->firstWhere('first_name', 'Budi');
                $foreign = $addresses->firstWhere('first_name', 'Siti');

                $this->assertSame('17601', data_get($raja, 'metadata.destination_id'));
                $this->assertNull(data_get($foreign, 'metadata.destination_id'));
            });

        // Web /checkout auto-applies default saved address & preserves destination_id
        $this->webCustomerWithCart($product, 1, null, $customer);
        $this->webLoadCheckout($customer)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('shippingAddress.destination_id', '17601')
                ->where('shippingAddress.destination_driver', 'rajaongkir')
                ->where('savedAddresses.0.destination_id', '17601')
            );

        // Mobile API POST /api/v1/checkout/shipping-address/saved succeeds 200 (not 422) & GET /api/v1/checkout preserves 17601
        $this->apiCustomerWithCart($product, 1, $customer);
        $this->apiApplySavedAddress($customer, $rajaAddress->id)
            ->assertOk()
            ->assertJsonPath('data.shipping_address.destination_id', '17601')
            ->assertJsonPath('data.saved_addresses.0.destination_id', '17601');

        $this->apiLoadCheckout($customer)
            ->assertOk()
            ->assertJsonPath('data.shipping_address.destination_id', '17601');

        // 3. Reverse: Delivery-Only ('komerce') saved address -> Starter ('rajaongkir') preserves destination_id
        $komerceAddress = Address::query()->create([
            'user_id' => $customer->id,
            'country_id' => $this->fixtures->country->id,
            'type' => AddressType::Shipping,
            'first_name' => 'Andi',
            'last_name' => 'Delivery',
            'street_address' => 'Jl. Sudirman No. 2',
            'postal_code' => '12190',
            'city' => 'Jakarta Selatan',
            'state' => 'DKI Jakarta',
            'phone_number' => '081200001111',
            'shipping_default' => true,
            'metadata' => json_encode([
                'destination_id' => '17547',
                'destination_label' => 'SENAYAN, KEBAYORAN BARU, JAKARTA SELATAN, DKI JAKARTA, 12190',
                'destination_driver' => 'komerce',
                'pin_point' => '-6.225,106.80',
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->applyTier('starter');
        $this->apiApplySavedAddress($customer, $komerceAddress->id)
            ->assertOk()
            ->assertJsonPath('data.shipping_address.destination_id', '17547');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier5;

use App\Actions\Account\ConfirmOrderReceived;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Integrations\Komerce\Komerce;
use App\Integrations\Komerce\Services\KomerceCarrierSync;
use App\Livewire\Shopper\OrderSummary;
use App\Models\OrderShipment;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShipmentStatus;
use Shopper\Core\Models\Carrier;
use Shopper\Core\Models\OrderItem;
use Shopper\Core\Models\OrderShipping;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class Iteration4AdversarialRemediationTest extends VendorPackageTierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireModules('Komerce');
    }

    public function test_bug1_manual_recovery_on_all_cancelled_komship_order_creates_live_manual_shipment_and_unblocks_all_surfaces(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $product = $this->fixtures->createStockedProduct(100000, 10, 500, $this->fixtures->inventory);

        ['order' => $order, 'shipment' => $cancelled] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_option_id' => null,
                'price_amount' => 112000,
            ],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::CANCELLED,
                'awb' => null,
                'tracking_number' => null,
                'cost' => 12000,
                'external_reference' => 'RO-ALL-CANC-R4',
            ],
            null,
            $product,
        );

        $this->cpanelCreateShippingLabel($admin, $order->fresh())
            ->set('data.carrier_id', $this->fixtures->carriers['manual']->id)
            ->set('data.tracking_number', 'MANUAL-RECOVERY-R4')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(NormalizeShipmentStatus::CANCELLED, $cancelled->fresh()->status);
        $this->assertNull($cancelled->fresh()->awb);

        $liveShipment = OrderShipment::query()
            ->with('lines')
            ->where('order_id', $order->id)
            ->where('status', '!=', NormalizeShipmentStatus::CANCELLED)
            ->sole();

        $this->assertSame('manual', $liveShipment->driver);
        $this->assertSame('MANUAL-RECOVERY-R4', $liveShipment->awb);
        $this->assertSame('MANUAL-RECOVERY-R4', $liveShipment->tracking_number);
        $this->assertSame(NormalizeShipmentStatus::LABELED, $liveShipment->status);
        $this->assertSame(12000, (int) $liveShipment->cost);
        $this->assertCount(1, $liveShipment->lines);
        $this->assertSame((int) $product->id, (int) $liveShipment->lines->first()->purchasable_id);
        $this->assertSame(1, (int) $liveShipment->lines->first()->qty);

        // Web storefront order show exposes active recovery shipment and shipping cost
        $this->withoutVite();
        $this->webShowOrder($customer, $order->fresh())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.shipping', 12000)
                ->where('shipments.0.status', NormalizeShipmentStatus::CANCELLED)
                ->where('shipments.0.can_track', false)
                ->where('shipments.1.awb', 'MANUAL-RECOVERY-R4')
                ->where('shipments.1.tracking_number', 'MANUAL-RECOVERY-R4')
                ->where('shipments.1.status', NormalizeShipmentStatus::LABELED)
                ->where('shipments.1.can_track', true));

        // Mobile API order show exposes active recovery shipment and shipping cost
        $this->apiShowOrder($customer, $order->number)
            ->assertOk()
            ->assertJsonPath('data.totals.shipping', 12000)
            ->assertJsonPath('data.breakdown.shipping', 12000)
            ->assertJsonPath('data.shipments.0.can_track', false)
            ->assertJsonPath('data.shipments.1.awb', 'MANUAL-RECOVERY-R4')
            ->assertJsonPath('data.shipments.1.tracking_number', 'MANUAL-RECOVERY-R4')
            ->assertJsonPath('data.shipments.1.status', NormalizeShipmentStatus::LABELED)
            ->assertJsonPath('data.shipments.1.can_track', true);

        // Cpanel label print controller returns 200 thermal label
        $this->cpanelPrintLabel($admin, $order->fresh())
            ->assertOk()
            ->assertViewIs('shopper.shipping.thermal-label')
            ->assertSee('MANUAL-RECOVERY-R4', false);

        // Cpanel KomerceOrderShipping reflects active manual AWB instead of all-cancelled Dibatalkan header
        $this->cpanelOrderShipping($admin, $order->fresh())
            ->assertViewHas('allCancelled', false)
            ->assertSee('Resi Manual Terbit', false)
            ->assertSee('Resi terbit', false)
            ->assertSee('MANUAL-RECOVERY-R4', false);

        // Cpanel OrderSummary preserves the order's shipping cost
        Livewire::actingAs($admin)
            ->test(OrderSummary::class, ['order' => $order->fresh()])
            ->assertViewHas('shippingPrice', 12000.0)
            ->assertSee('12.000', false);

        // Customer ConfirmOrderReceived succeeds
        $this->apiConfirmReceived($customer, $order->number)
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Completed->value);

        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $liveShipment->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_bug1_manual_recovery_without_tracking_number_and_order_summary_zero_cost_fallback(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();
        $productA = $this->fixtures->createStockedProduct(60000, 10, 300, $this->fixtures->inventory);
        $productB = $this->fixtures->createStockedProduct(40000, 10, 200, $this->fixtures->inventory);

        ['order' => $order, 'shipment' => $cancelled] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
                'shipping_option_id' => null,
                'price_amount' => 112000,
            ],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::CANCELLED,
                'awb' => 'OLD-CANC-BLANK-1',
                'tracking_number' => 'OLD-CANC-BLANK-1',
                'cost' => 12000,
            ],
            null,
            $productA,
        );

        $itemB = OrderItem::query()->create([
            'order_id' => $order->id,
            'name' => $productB->name,
            'sku' => $productB->sku ?? 'SKU-B',
            'product_type' => $productB->getMorphClass(),
            'product_id' => $productB->id,
            'quantity' => 1,
            'unit_price_amount' => 40000,
        ]);
        $cancelled->lines()->create([
            'purchasable_type' => $productB->getMorphClass(),
            'purchasable_id' => $productB->id,
            'qty' => 1,
        ]);

        // Recover only item B without a tracking number (local courier)
        $itemA = OrderItem::query()->where('order_id', $order->id)->where('product_id', $productA->id)->sole();
        $this->cpanelCreateShippingLabel($admin, $order->fresh())
            ->set('data.carrier_id', $this->fixtures->carriers['manual']->id)
            ->set('data.tracking_number', '')
            ->set('data.items', [['item_id' => $itemB->id]])
            ->call('save')
            ->assertHasNoErrors();

        $liveShipment = OrderShipment::query()
            ->with('lines')
            ->where('order_id', $order->id)
            ->where('status', '!=', NormalizeShipmentStatus::CANCELLED)
            ->sole();

        $this->assertSame('manual', $liveShipment->driver);
        $this->assertNull($liveShipment->awb);
        $this->assertCount(1, $liveShipment->lines);
        $this->assertSame((int) $productB->id, (int) $liveShipment->lines->first()->purchasable_id);
        $this->assertNotNull($itemA->fresh());

        // Even if a replacement shipment had cost=0 while cancelled had cost=12000, OrderSummary preserves 12000
        $liveShipment->forceFill(['cost' => 0])->save();
        Livewire::actingAs($admin)
            ->test(OrderSummary::class, ['order' => $order->fresh()])
            ->assertViewHas('shippingPrice', 12000.0);

        // ConfirmOrderReceived still refuses a blank-AWB local shipment cleanly
        $this->expectException(ValidationException::class);
        resolve(ConfirmOrderReceived::class)->handle($order->fresh());
    }

    public function test_bug2_komerce_carrier_sync_continues_in_komship_mode_when_shipping_cost_returns_429_or_401(): void
    {
        // 1. Shipping Cost returns 429 quota exhausted while Delivery succeeds
        $this->applyTier('komship')
            ->withCostQuotaExhausted(429, 'Daily request limit exceeded')
            ->apply();

        $syncResult429 = resolve(KomerceCarrierSync::class)->sync();

        $this->assertTrue($syncResult429->successful, $syncResult429->message);
        $this->assertTrue(Komerce::shippingDeliveryEnabled());
        $this->assertContains('jne', $syncResult429->enabled);
        $this->assertContains('jnt', $syncResult429->enabled);
        $this->assertTrue((bool) Carrier::query()->where('slug', 'jne')->value('is_enabled'));
        $this->assertFalse((bool) Carrier::query()->where('slug', 'pos')->value('is_enabled'));

        // 2. Shipping Cost returns 401 unauthorized while Delivery succeeds
        $this->applyTier('komship')
            ->withCostUnauthorized(401, 'Invalid API Key')
            ->apply();

        $syncResult401 = resolve(KomerceCarrierSync::class)->sync();

        $this->assertTrue($syncResult401->successful, $syncResult401->message);
        $this->assertFalse(Komerce::shippingCostEnabled());
        $this->assertTrue(Komerce::shippingDeliveryEnabled());
        $this->assertContains('jne', $syncResult401->enabled);
        $this->assertContains('spx', $syncResult401->enabled);

        // 3. Both Shipping Cost and Shipping Delivery fail -> sync fails without modifying carriers
        $this->applyTier('komship')
            ->withCostQuotaExhausted(429, 'Daily request limit exceeded')
            ->withDeliveryEntitled(false, 500, 'Delivery error')
            ->apply();

        $failedResult = resolve(KomerceCarrierSync::class)->sync();
        $this->assertFalse($failedResult->successful);

        Http::assertSent(static fn ($req): bool => str_contains($req->url(), '/tariff/api/v1/calculate'));
    }

    public function test_bug3_order_detail_mark_complete_stays_hidden_on_stale_cancelled_order_shipping_until_genuine_recovery(): void
    {
        $this->applyTier('komship');
        $admin = $this->fixtures->createAdmin();
        $customer = $this->fixtures->createVerifiedCustomer();

        ['order' => $order] = $this->fixtures->createOrderWithShipment(
            $customer,
            [
                'status' => OrderStatus::Processing,
                'payment_status' => PaymentStatus::Paid,
            ],
            [
                'driver' => 'komerce',
                'status' => NormalizeShipmentStatus::CANCELLED,
                'awb' => 'CANC-STALE-AWB-3',
                'tracking_number' => 'CANC-STALE-AWB-3',
                'external_reference' => 'RO-CANC-STALE-3',
            ],
        );

        // Stale OrderShipping belonging to the cancelled shipment remains bound on order_items
        $staleShipping = OrderShipping::query()->create([
            'order_id' => $order->id,
            'carrier_id' => $this->fixtures->carriers['jne']->id,
            'tracking_number' => 'CANC-STALE-AWB-3',
            'status' => ShipmentStatus::Pending,
        ]);
        OrderItem::query()->where('order_id', $order->id)->update(['order_shipping_id' => $staleShipping->id]);

        // Must NOT spawn a live manual shipment for a stale AWB matching a cancelled shipment
        $this->assertSame(
            0,
            OrderShipment::query()
                ->where('order_id', $order->id)
                ->where('status', '!=', NormalizeShipmentStatus::CANCELLED)
                ->count(),
        );

        // markCompleteAction MUST remain hidden while only cancelled shipments + stale OrderShipping exist
        $this->cpanelOrderDetail($admin, $order->fresh())
            ->assertActionHidden('markComplete');

        // Detach stale binding and perform genuine manual recovery -> markCompleteAction becomes visible
        OrderItem::query()->where('order_id', $order->id)->update(['order_shipping_id' => null]);

        $this->cpanelCreateShippingLabel($admin, $order->fresh())
            ->set('data.carrier_id', $this->fixtures->carriers['manual']->id)
            ->set('data.tracking_number', 'MANUAL-REC-R4-3')
            ->call('save')
            ->assertHasNoErrors();

        $this->cpanelOrderDetail($admin, $order->fresh())
            ->assertActionVisible('markComplete');
    }
}

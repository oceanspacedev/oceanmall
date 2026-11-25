<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Actions\Notify\NotifyOrderCustomer;
use App\Actions\Shipping\NormalizeShipmentStatus;
use App\Enums\OrderNotificationType;
use App\Models\OrderShipment;
use App\Models\User;
use App\Notifications\OrderStatusMail;
use App\Notifications\OrderStatusNotification;
use App\Notifications\QueuedVerifyEmail;
use App\Payment\PaymentMethodCatalog;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Shopper\Core\Enum\DiscountApplyTo;
use Shopper\Core\Enum\DiscountEligibility;
use Shopper\Core\Enum\DiscountRequirement;
use Shopper\Core\Enum\DiscountType;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Events\Orders\OrderCancelled;
use Shopper\Core\Events\Orders\OrderCompleted;
use Shopper\Core\Models\Discount;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Tests\TestCase;

/**
 * Payment and order findings of the production-readiness QA: amount limits
 * of a payment method, coupon uses of orders nobody paid for, "order
 * received" on a partly shipped order, and e-mails sent inside requests.
 */
final class PaymentOrderHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_a_payment_method_states_why_it_cannot_take_an_amount(): void
    {
        $method = ['title' => 'QRIS', 'min_amount' => 10000, 'max_amount' => 10000000];

        $this->assertNull(PaymentMethodCatalog::amountOutOfRange($method, 10000));
        $this->assertNull(PaymentMethodCatalog::amountOutOfRange($method, 10000000));
        $this->assertSame('QRIS hanya untuk total minimal Rp10.000. Pilih metode lain.', PaymentMethodCatalog::amountOutOfRange($method, 9999));
        $this->assertSame('QRIS hanya untuk total maksimal Rp10.000.000. Pilih metode lain.', PaymentMethodCatalog::amountOutOfRange($method, 10000001));
        // A method without limits (manual, COD) takes any amount.
        $this->assertNull(PaymentMethodCatalog::amountOutOfRange(['title' => 'COD'], 1));
        $this->assertNull(PaymentMethodCatalog::amountOutOfRange(['title' => 'COD', 'min_amount' => null, 'max_amount' => 0], 5));
    }

    public function test_an_unpaid_cancelled_order_gives_its_coupon_use_back_once(): void
    {
        $coupon = $this->coupon('SEKALI', limit: 1, used: 1);
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'discount_id' => $coupon->id,
            'status' => OrderStatus::Cancelled,
            'payment_status' => PaymentStatus::Voided,
            'currency_code' => 'IDR',
            'metadata' => json_encode(['shipping_address' => ['city' => 'Cirebon']]),
        ]);

        event(new OrderCancelled($order));
        // The event is fired again for an already cancelled order.
        event(new OrderCancelled($order));

        $this->assertSame(0, (int) $coupon->fresh()->total_use, 'Given back once, never below zero.');

        $order->refresh();
        $metadata = json_decode((string) $order->getRawOriginal('metadata'), true);
        // Shopper's "one use per customer" rule counts orders that carry the discount.
        $this->assertNull($order->discount_id);
        $this->assertSame($coupon->id, $metadata['released_discount_id']);
        $this->assertNotEmpty($metadata['discount_released_at']);
        $this->assertSame('Cirebon', $metadata['shipping_address']['city'], 'The rest of the metadata is kept.');
    }

    public function test_a_paid_order_that_is_cancelled_keeps_its_coupon_use(): void
    {
        $coupon = $this->coupon('TERPAKAI', limit: 5, used: 3);
        $order = Order::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'discount_id' => $coupon->id,
            'status' => OrderStatus::Cancelled,
            'payment_status' => PaymentStatus::Paid,
            'currency_code' => 'IDR',
        ]);

        event(new OrderCancelled($order));

        $this->assertSame(3, (int) $coupon->fresh()->total_use);
        $this->assertSame($coupon->id, $order->fresh()->discount_id);
    }

    public function test_order_received_needs_every_parcel_to_have_shipped(): void
    {
        [$customer, $order] = $this->paidOrder();
        $shipped = $this->shipment($order, NormalizeShipmentStatus::IN_TRANSIT, 'JNE-1');
        $waiting = $this->shipment($order, 'pending', null);

        $confirm = fn () => $this->actingAs($customer)
            ->from(route('account.orders.show', $order))
            ->post(route('account.orders.confirm-received', $order));

        $confirm()->assertSessionHasErrors('received');

        $this->assertSame(NormalizeShipmentStatus::IN_TRANSIT, $shipped->fresh()->status);
        $this->assertSame('pending', $waiting->fresh()->status, 'A parcel that never left is not delivered.');
        $this->assertNotSame(OrderStatus::Completed, $order->fresh()->status);

        // The second parcel ships: now the order can be confirmed.
        $waiting->forceFill(['status' => NormalizeShipmentStatus::IN_TRANSIT, 'awb' => 'JNE-2'])->save();
        Event::fake([OrderCompleted::class]);

        $confirm()->assertSessionHasNoErrors();

        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $shipped->fresh()->status);
        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $waiting->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertSame(ShippingStatus::Delivered, $order->fresh()->shipping_status);
        Event::assertDispatchedTimes(OrderCompleted::class, 1);
    }

    public function test_a_cancelled_parcel_is_neither_waited_for_nor_marked_delivered(): void
    {
        [$customer, $order] = $this->paidOrder();
        $shipped = $this->shipment($order, NormalizeShipmentStatus::IN_TRANSIT, 'JNE-1');
        $cancelled = $this->shipment($order, NormalizeShipmentStatus::CANCELLED, null);

        $this->actingAs($customer)
            ->from(route('account.orders.show', $order))
            ->post(route('account.orders.confirm-received', $order))
            ->assertSessionHasNoErrors();

        $this->assertSame(NormalizeShipmentStatus::DELIVERED, $shipped->fresh()->status);
        $this->assertSame(NormalizeShipmentStatus::CANCELLED, $cancelled->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_the_order_mail_is_queued_and_the_in_app_notification_is_stored_at_once(): void
    {
        Queue::fake();
        [$customer, $order] = $this->paidOrder();

        resolve(NotifyOrderCustomer::class)->handle($order, OrderNotificationType::Paid);

        $this->assertSame(1, $customer->notifications()->count(), 'Stored at once: it also keeps each type to one send.');
        $this->assertSame(['database'], (new OrderStatusNotification($order, OrderNotificationType::Paid))->via($customer));
        $this->assertInstanceOf(ShouldQueue::class, new OrderStatusMail($order, OrderNotificationType::Paid));
        Queue::assertPushed(
            SendQueuedNotifications::class,
            static fn (SendQueuedNotifications $job): bool => $job->notification instanceof OrderStatusMail,
        );

        // Once per order and type: no second mail.
        resolve(NotifyOrderCustomer::class)->handle($order, OrderNotificationType::Paid);
        Queue::assertPushed(SendQueuedNotifications::class, 1);
    }

    public function test_the_verification_mail_is_queued_and_sent_once_per_registration(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        event(new Registered($user));

        Notification::assertSentToTimes($user, QueuedVerifyEmail::class, 1);
        $this->assertInstanceOf(ShouldQueue::class, new QueuedVerifyEmail);

        // A resend moments later (the "send again" button) still goes out.
        $this->travel(10)->seconds();
        $user->sendEmailVerificationNotification();
        Notification::assertSentToTimes($user, QueuedVerifyEmail::class, 2);
    }

    public function test_app_registration_completes_even_when_the_mail_cannot_be_queued(): void
    {
        Event::listen(Registered::class, static function (): void {
            throw new \RuntimeException('mail queue is down');
        });

        $this->postJson('/api/v1/auth/register', [
            'first_name' => 'Sari',
            'last_name' => 'Dewi',
            'email' => 'sari@example.com',
            'password' => 'password-Kuat-9!',
            'password_confirmation' => 'password-Kuat-9!',
        ])->assertCreated()->assertJsonStructure(['token']);

        $this->assertNotNull(User::findByEmail('sari@example.com'));
    }

    private function coupon(string $code, int $limit, int $used): Discount
    {
        return Discount::query()->create([
            'code' => $code,
            'is_active' => true,
            'type' => DiscountType::FixedAmount,
            'value' => 50000,
            'apply_to' => DiscountApplyTo::Order->value,
            'min_required' => DiscountRequirement::None->value,
            'eligibility' => DiscountEligibility::Everyone->value,
            'usage_limit' => $limit,
            'usage_limit_per_user' => true,
            'total_use' => $used,
            'start_at' => now()->subDay(),
            'end_at' => now()->addYear(),
        ]);
    }

    /**
     * @return array{0: User, 1: Order}
     */
    private function paidOrder(): array
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Shipped,
            'currency_code' => 'IDR',
        ]);

        return [$customer, $order];
    }

    private function shipment(Order $order, string $status, ?string $awb): OrderShipment
    {
        return OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => Inventory::factory()->create()->id,
            'carrier_code' => 'jne',
            'carrier_name' => 'JNE',
            'service_code' => 'REG',
            'service_name' => 'Regular',
            'cost' => 18000,
            'currency_code' => 'IDR',
            'status' => $status,
            'awb' => $awb,
            'tracking_number' => $awb,
        ]);
    }
}

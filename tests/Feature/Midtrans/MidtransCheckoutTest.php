<?php

declare(strict_types=1);

namespace Tests\Feature\Midtrans;

use App\Actions\Checkout\InitiateOrderPayment;
use App\Payment\PaymentActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Shopper\Core\Models\Order;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\TestCase;
use Throwable;

/**
 * Checkout with a Midtrans method goes through the generic
 * InitiateOrderPayment step and returns the neutral `redirect` action to the
 * Snap page, with no Midtrans branch in checkout.
 */
final class MidtransCheckoutTest extends TestCase
{
    use InteractsWithMidtrans;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withMidtrans();
        $this->travelTo(Carbon::parse('2026-11-19 10:00:00', 'UTC'));
    }

    public function test_checkout_creates_a_snap_transaction_and_returns_a_redirect_action(): void
    {
        Http::fake([self::SNAP_URL => Http::response([
            'token' => 'snap-token-1',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-1',
        ], 201)]);

        $order = $this->midtransOrder('bca_va');

        ['result' => $result, 'action' => $action] = resolve(InitiateOrderPayment::class)->handle($order);

        $this->assertTrue($result->success);
        $this->assertSame('ORD-2026-0001', $result->reference);
        $this->assertSame('midtrans', $action['driver']);
        $this->assertSame('redirect', $action['kind']);
        $this->assertSame('https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token-1', $action['data']['url']);
        $this->assertSame(150000, $action['data']['amount']);
        $this->assertSame(['payment_url' => $action['data']['url']], PaymentActions::legacyInstructions($action));

        Http::assertSent(function (Request $request) use ($order): bool {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === self::SNAP_URL
                && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::SERVER_KEY.':'))
                && $body['transaction_details'] === ['order_id' => 'ORD-2026-0001', 'gross_amount' => 150000]
                && $body['enabled_payments'] === ['bca_va']
                && $body['expiry'] === ['start_time' => '2026-11-19 17:00:00 +0700', 'unit' => 'minute', 'duration' => 1440]
                && $body['page_expiry'] === ['unit' => 'minute', 'duration' => 1440]
                && $body['callbacks']['finish'] === route('account.orders.show', ['order' => $order->id])
                && ! isset($body['credit_card']);
        });

        $transaction = PaymentTransaction::query()->where('order_id', $order->id)->sole();
        $this->assertSame('midtrans', $transaction->driver);
        $this->assertSame(TransactionType::Initiate, $transaction->type);
        $this->assertSame(TransactionStatus::Pending, $transaction->status);
        $this->assertSame('ORD-2026-0001', $transaction->reference);

        $driver = resolve(PaymentActions::class)->driver($order->refresh());
        $this->assertTrue($driver->paymentExpiresAt($order)?->equalTo(Carbon::parse('2026-11-20 10:00:00', 'UTC')));
        $this->assertSame($action, resolve(PaymentActions::class)->forOrder($order), 'The order page shows the same pay link later.');
        $this->assertFalse(resolve(PaymentActions::class)->canRetry($order), 'An open Snap page is not replaced.');
    }

    public function test_item_details_add_up_to_the_gross_amount(): void
    {
        Http::fake([self::SNAP_URL => Http::response(['token' => 't', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/t'], 201)]);

        $order = $this->midtransOrder('other_qris');
        $order->items()->create([
            'name' => 'Kaos Lengan Panjang Edisi Spesial Dengan Nama Yang Sangat Panjang Sekali',
            'sku' => 'KAOS-1',
            'quantity' => 2,
            'unit_price_amount' => 60000,
            'product_id' => 1,
            'product_type' => 'product',
        ]);

        resolve(InitiateOrderPayment::class)->handle($order);

        Http::assertSent(function (Request $request): bool {
            $items = $request->data()['item_details'] ?? [];
            $sum = array_sum(array_map(static fn (array $item): int => $item['price'] * $item['quantity'], $items));

            return $sum === 150000
                && count($items) === 2
                && mb_strlen($items[0]['name']) <= 50
                && $items[1] === ['id' => 'shipping-fees', 'name' => 'Ongkos kirim & biaya lain', 'price' => 30000, 'quantity' => 1]
                && $request->data()['enabled_payments'] === ['other_qris'];
        });
    }

    public function test_lines_above_the_total_are_left_out_rather_than_refused(): void
    {
        Http::fake([self::SNAP_URL => Http::response(['token' => 't', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/t'], 201)]);

        $order = $this->midtransOrder('credit_card');
        $order->items()->create([
            'name' => 'Diskon besar', 'sku' => 'X', 'quantity' => 1, 'unit_price_amount' => 200000,
            'product_id' => 1, 'product_type' => 'product',
        ]);

        resolve(InitiateOrderPayment::class)->handle($order);

        Http::assertSent(fn (Request $request): bool => ! isset($request->data()['item_details'])
            && $request->data()['credit_card'] === ['secure' => true]);
    }

    public function test_a_taken_order_id_moves_to_the_next_attempt(): void
    {
        Http::fakeSequence(self::SNAP_URL)
            ->push(['error_messages' => ['transaction_details.order_id sudah digunakan']], 400)
            ->push(['token' => 't2', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/t2'], 201);

        $order = $this->midtransOrder();

        ['result' => $result] = resolve(InitiateOrderPayment::class)->handle($order);

        $this->assertSame('ORD-2026-0001~2', $result->reference);
        $this->assertSame('ORD-2026-0001~2', PaymentTransaction::query()->where('order_id', $order->id)->value('reference'));
        Http::assertSentCount(2);
    }

    public function test_a_refused_snap_request_fails_checkout_payment_loudly(): void
    {
        Http::fake([self::SNAP_URL => Http::response(['error_messages' => ['Access denied due to unauthorized transaction']], 401)]);

        try {
            resolve(InitiateOrderPayment::class)->handle($this->midtransOrder());
            $this->fail('Expected the Snap refusal to surface.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('unauthorized transaction', $e->getMessage());
        }

        $this->assertSame(0, PaymentTransaction::query()->count());
    }

    public function test_a_configured_finish_url_wins(): void
    {
        config()->set('midtrans.finish_url', 'https://shop.example.test/thanks');
        Http::fake([self::SNAP_URL => Http::response(['token' => 't', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/t'], 201)]);

        resolve(InitiateOrderPayment::class)->handle($this->midtransOrder());

        Http::assertSent(fn (Request $request): bool => $request->data()['callbacks']['finish'] === 'https://shop.example.test/thanks');
    }

    public function test_non_idr_orders_are_refused_without_calling_midtrans(): void
    {
        Http::fake();

        try {
            resolve(InitiateOrderPayment::class)->handle($this->midtransOrder('bca_va', ['currency_code' => 'USD']));
            $this->fail('Expected a refusal.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('IDR', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertInstanceOf(Order::class, Order::query()->first());
    }
}

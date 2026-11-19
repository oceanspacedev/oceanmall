<?php

declare(strict_types=1);

namespace Tests\Feature\Xendit;

use App\Actions\Checkout\FetchPaymentMethods;
use App\Actions\Checkout\InitiateOrderPayment;
use App\Integrations\Xendit\Exceptions\XenditNotConfiguredException;
use App\Integrations\Xendit\Payment\XenditDriver;
use App\Integrations\Xendit\Services\XenditClient;
use App\Integrations\Xendit\Xendit;
use App\Models\User;
use App\Payment\PaymentActions;
use App\Payment\PaymentMethodCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Country;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\Zone;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Facades\Payment;
use Shopper\Payment\Models\PaymentTransaction;
use Tests\Feature\Xendit\Concerns\FakesXendit;
use Tests\TestCase;

final class XenditDriverTest extends TestCase
{
    use FakesXendit;
    use RefreshDatabase;

    public function test_the_key_decides_readiness_and_mode(): void
    {
        $this->assertFalse(Xendit::configured());
        $this->assertFalse(Payment::driver(XenditDriver::CODE)->isConfigured());
        $this->assertNull(Xendit::mode());

        config()->set('xendit.secret_key', 'not-a-xendit-key');
        $this->assertFalse(Xendit::configured());
        $this->assertStringContainsString('not a Xendit secret key', (string) Xendit::refusal());

        config()->set('xendit.secret_key', 'xnd_development_abc');
        $this->assertTrue(Xendit::configured());
        $this->assertSame(Xendit::MODE_TEST, Xendit::mode());

        config()->set('xendit.secret_key', 'xnd_production_abc');
        $this->assertTrue(Xendit::configured());
        $this->assertSame(Xendit::MODE_LIVE, Xendit::mode());
    }

    public function test_a_development_key_is_refused_in_production_unless_allowed(): void
    {
        Http::fake();
        $this->app['env'] = 'production';

        config()->set('xendit.secret_key', 'xnd_development_abc');
        $this->assertFalse(Xendit::configured());
        $this->assertStringContainsString('APP_ENV=production', (string) Xendit::refusal());

        try {
            resolve(XenditClient::class)->getInvoice('inv_1');
            $this->fail('No request may be sent with a refused key.');
        } catch (XenditNotConfiguredException) {
            Http::assertNothingSent();
        }

        config()->set('xendit.allow_development_key_in_production', true);
        $this->assertTrue(Xendit::configured());

        config()->set('xendit.allow_development_key_in_production', false);
        config()->set('xendit.secret_key', 'xnd_production_abc');
        $this->assertTrue(Xendit::configured());
    }

    public function test_xendit_methods_are_offered_only_with_a_key_and_the_operator_switch(): void
    {
        $country = Country::factory()->create();
        $zone = Zone::factory()->create(['is_enabled' => true]);
        $zone->countries()->attach($country->id);
        $zone->paymentMethods()->attach($this->xenditPaymentMethod('BCA')->id);

        $this->assertSame([], resolve(FetchPaymentMethods::class)->handle($country->id), 'Keyless: not offered.');

        $this->configureXendit();
        $rows = resolve(FetchPaymentMethods::class)->handle($country->id);
        $this->assertSame(['xendit'], array_column($rows, 'driver'));
        $this->assertSame('BCA', $rows[0]['channel_code']);
        $this->assertStringContainsString('images/payments/bca.svg', (string) $rows[0]['logo']);

        config()->set('shopper.payment.drivers.xendit.enabled', false);
        $this->assertFalse((new PaymentMethodCatalog)->driverReady(XenditDriver::CODE));
        $this->assertSame([], resolve(FetchPaymentMethods::class)->handle($country->id));
    }

    public function test_checkout_creates_an_invoice_restricted_to_the_chosen_channel_and_returns_a_redirect(): void
    {
        $this->configureXendit();
        $this->fakeXenditApi();
        config()->set('xendit.invoice_duration', 3600);

        $user = User::factory()->create(['email' => 'buyer@example.com', 'first_name' => 'Budi', 'last_name' => 'Santoso', 'phone_number' => '0812-3456-7890']);
        $order = Order::factory()->create([
            'number' => 'ORD-2001',
            'price_amount' => 125000,
            'currency_code' => 'IDR',
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'payment_method_id' => $this->xenditPaymentMethod('BCA')->id,
            'customer_id' => $user->id,
        ]);

        ['result' => $result, 'action' => $action] = resolve(InitiateOrderPayment::class)->handle($order);

        $this->assertTrue($result->success);
        $this->assertSame('inv_new_1', $result->reference);
        $this->assertSame([
            'driver' => 'xendit',
            'kind' => 'redirect',
            'data' => [
                'url' => 'https://checkout-staging.xendit.co/web/inv_new_1',
                'expiry_date' => $this->xenditInvoices['inv_new_1']['expiry_date'],
                'channel_code' => 'BCA',
            ],
        ], $action);

        Http::assertSent(function (Request $request) use ($order): bool {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://api.xendit.co/v2/invoices'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('xnd_development_secret:'))
                && $data['external_id'] === 'ORD-2001'
                && $data['amount'] === 125000 // integer rupiah, never x100
                && $data['currency'] === 'IDR'
                && $data['payment_methods'] === ['BCA']
                && $data['invoice_duration'] === 3600
                && $data['payer_email'] === 'buyer@example.com'
                && $data['customer']['mobile_number'] === '+6281234567890'
                && $data['success_redirect_url'] === route('account.orders.show', $order)
                && $data['failure_redirect_url'] === route('account.orders.show', $order);
        });

        $order->refresh();
        $state = json_decode((string) $order->getAttribute('metadata'), true)['xendit'];
        $this->assertSame('inv_new_1', $state['invoice_id']);
        $this->assertSame('ORD-2001', $state['external_id']);
        $this->assertSame('test', $state['mode']);

        $transaction = PaymentTransaction::query()->where('order_id', $order->id)->where('type', TransactionType::Initiate)->sole();
        $this->assertSame('inv_new_1', $transaction->reference);
        $this->assertSame('xendit', $transaction->driver);
        $this->assertSame(125000, (int) $transaction->amount);
        $this->assertNotNull(data_get($transaction->metadata, 'expiry_date'));

        // The mobile API reads the same pay link back.
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/orders/ORD-2001')
            ->assertOk()
            ->assertJsonPath('data.payment_action.kind', 'redirect')
            ->assertJsonPath('data.payment_action.data.url', 'https://checkout-staging.xendit.co/web/inv_new_1');
    }

    public function test_initiation_without_a_key_sends_nothing(): void
    {
        Http::fake();
        $order = $this->xenditOrder();

        $this->expectException(\Throwable::class);

        try {
            Payment::driver(XenditDriver::CODE)->initiatePayment(150000, 'IDR', ['order' => $order]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_xendit_error_fails_initiation_without_a_pay_link(): void
    {
        $this->configureXendit();
        Http::fake(['https://api.xendit.co/*' => Http::response(['error_code' => 'API_VALIDATION_ERROR', 'message' => 'bad'], 400)]);

        $order = Order::factory()->create([
            'price_amount' => 125000,
            'currency_code' => 'IDR',
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'payment_method_id' => $this->xenditPaymentMethod('QRIS')->id,
        ]);

        try {
            resolve(InitiateOrderPayment::class)->handle($order);
            $this->fail('A refused invoice must surface as an error.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('API_VALIDATION_ERROR', $e->getMessage());
        }

        $this->assertNull(resolve(PaymentActions::class)->forOrder($order->refresh()));
        $this->assertTrue(Payment::driver(XenditDriver::CODE)->canRetryPayment($order), 'No invoice yet: pay again creates one.');
    }

    public function test_the_payment_action_disappears_once_the_invoice_expired(): void
    {
        $this->configureXendit();
        $order = $this->xenditOrder('inv_1', 150000, ['expiry_date' => now()->addHour()->toIso8601String()]);
        $driver = Payment::driver(XenditDriver::CODE);

        $this->assertSame('redirect', $driver->paymentAction($order)['kind']);
        $this->assertFalse($driver->canRetryPayment($order), 'A payable invoice is opened again, not replaced.');

        $this->travel(2)->hours();

        $this->assertNull($driver->paymentAction($order->refresh()));
        $this->assertTrue($driver->canRetryPayment($order));
    }

    public function test_retrieve_and_cancel_map_invoice_statuses(): void
    {
        $this->configureXendit();
        $this->fakeXenditApi();
        $this->putXenditInvoice('inv_paid', ['status' => 'SETTLED', 'paid_amount' => 150000]);
        $this->putXenditInvoice('inv_open');
        $driver = Payment::driver(XenditDriver::CODE);

        $paid = $driver->retrievePayment('inv_paid');
        $this->assertSame('captured', $paid->status);
        $this->assertSame(150000, $paid->amount);

        $this->assertSame('pending', $driver->retrievePayment('inv_open')->status);

        $cancelled = $driver->cancelPayment('inv_open');
        $this->assertTrue($cancelled->success);
        $this->assertSame('canceled', $cancelled->status);
        $this->assertSame('failed', $driver->retrievePayment('inv_open')->status);

        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.xendit.co/invoices/inv_open/expire!');
    }
}

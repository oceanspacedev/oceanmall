<?php

declare(strict_types=1);

namespace Tests\Feature\Xendit\Concerns;

use App\Integrations\Xendit\Payment\XenditDriver;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Models\PaymentTransaction;

/**
 * A stateful fake of the Xendit Invoice API (create / get / expire!) on
 * Laravel's Http::fake, plus helpers to configure the module and build an
 * order with a Xendit invoice.
 */
trait FakesXendit
{
    /** @var array<string, array<string, mixed>> invoice id => invoice */
    protected array $xenditInvoices = [];

    /** @var list<string> invoice ids whose GET answers 500 */
    protected array $xenditUnreachable = [];

    protected int $xenditCreated = 0;

    protected function configureXendit(string $key = 'xnd_development_secret', string $token = 'callback-token'): void
    {
        config()->set('xendit.secret_key', $key);
        config()->set('xendit.callback_token', $token);
        config()->set('xendit.base_url', 'https://api.xendit.co');
        config()->set('shopper.payment.drivers.xendit.enabled', true);
    }

    protected function fakeXenditApi(): void
    {
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'POST' && $path === '/v2/invoices') {
                $this->xenditCreated++;
                $id = 'inv_new_'.$this->xenditCreated;
                $data = $request->data();
                $this->xenditInvoices[$id] = [
                    'id' => $id,
                    'external_id' => $data['external_id'],
                    'status' => 'PENDING',
                    'amount' => $data['amount'],
                    'currency' => $data['currency'] ?? 'IDR',
                    'invoice_url' => 'https://checkout-staging.xendit.co/web/'.$id,
                    'expiry_date' => now()->addSeconds((int) ($data['invoice_duration'] ?? 86400))->toIso8601String(),
                ];

                return Http::response($this->xenditInvoices[$id]);
            }

            if ($request->method() === 'GET' && preg_match('#^/v2/invoices/([^/]+)$#', $path, $m) === 1) {
                $id = rawurldecode($m[1]);

                if (in_array($id, $this->xenditUnreachable, true)) {
                    return Http::response(['error_code' => 'SERVER_ERROR', 'message' => 'down'], 500);
                }

                return isset($this->xenditInvoices[$id])
                    ? Http::response($this->xenditInvoices[$id])
                    : Http::response(['error_code' => 'INVOICE_NOT_FOUND_ERROR', 'message' => 'not found'], 404);
            }

            if ($request->method() === 'POST' && preg_match('#^/invoices/([^/]+)/expire!$#', $path, $m) === 1) {
                $id = rawurldecode($m[1]);

                if (! isset($this->xenditInvoices[$id])) {
                    return Http::response(['error_code' => 'INVOICE_NOT_FOUND_ERROR', 'message' => 'not found'], 404);
                }

                if ($this->xenditInvoices[$id]['status'] !== 'PENDING') {
                    return Http::response(['error_code' => 'INVOICE_NOT_FOUND_ERROR', 'message' => 'Invoice is not pending'], 404);
                }

                $this->xenditInvoices[$id]['status'] = 'EXPIRED';

                return Http::response($this->xenditInvoices[$id]);
            }

            return Http::response(['error_code' => 'UNEXPECTED', 'message' => $request->method().' '.$path], 500);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function putXenditInvoice(string $id, array $overrides = []): void
    {
        $this->xenditInvoices[$id] = [
            'id' => $id,
            'external_id' => 'ORD-1001',
            'status' => 'PENDING',
            'amount' => 150000,
            'currency' => 'IDR',
            'invoice_url' => 'https://checkout-staging.xendit.co/web/'.$id,
            'expiry_date' => now()->addDay()->toIso8601String(),
            ...$overrides,
        ];
    }

    protected function xenditPaymentMethod(string $channel = 'BCA'): PaymentMethod
    {
        return PaymentMethod::factory()->create([
            'driver' => XenditDriver::CODE,
            'slug' => 'xendit-test-'.strtolower($channel).'-'.uniqid(),
            'is_enabled' => true,
            'metadata' => json_encode(['payment_type' => 'bank_transfer', 'channel_code' => $channel]),
        ]);
    }

    /**
     * An unpaid order whose current Xendit invoice is $invoiceId.
     *
     * @param  array<string, mixed>  $state  metadata.xendit overrides
     */
    protected function xenditOrder(string $invoiceId = 'inv_1', int $amount = 150000, array $state = []): Order
    {
        $order = Order::factory()->create([
            'number' => 'ORD-1001',
            'price_amount' => $amount,
            'currency_code' => 'IDR',
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'payment_method_id' => $this->xenditPaymentMethod()->id,
            'customer_id' => User::factory()->create()->id,
            'metadata' => json_encode(['xendit' => [
                'invoice_id' => $invoiceId,
                'external_id' => 'ORD-1001',
                'invoice_url' => 'https://checkout-staging.xendit.co/web/'.$invoiceId,
                'expiry_date' => now()->addDay()->toIso8601String(),
                'status' => 'PENDING',
                'channel_code' => 'BCA',
                'attempt' => 1,
                ...$state,
            ]]),
        ]);

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $order->payment_method_id,
            'driver' => XenditDriver::CODE,
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Success,
            'amount' => $amount,
            'currency_code' => 'IDR',
            'reference' => $invoiceId,
        ]);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postXenditCallback(array $payload, ?string $token = 'callback-token'): TestResponse
    {
        $headers = $token === null ? [] : ['x-callback-token' => $token];

        return $this->withHeaders($headers)->postJson('/webhooks/xendit/invoice', $payload);
    }
}

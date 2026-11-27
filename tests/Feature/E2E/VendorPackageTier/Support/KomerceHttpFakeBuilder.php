<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/**
 * Fluent HTTP fake builder for deterministic Komerce / RajaOngkir API simulation
 * across all 4 product families (Shipping Cost, Shipping Delivery, Payment API, QRISLY).
 */
final class KomerceHttpFakeBuilder
{
    public const COST_BASE = 'https://cost.example.test';

    public const DELIVERY_BASE = 'https://delivery.example.test';

    public const PAYMENT_BASE = 'https://payment.example.test/user';

    public const QRISLY_BASE = 'https://qrisly.example.test/user';

    /** @var array<string, mixed> */
    private array $routes = [];

    private function __construct() {}

    public static function make(): self
    {
        return new self;
    }

    public static function forTier(string $tier): self
    {
        $builder = new self;

        return match ($tier) {
            'keyless' => $builder,
            'starter' => $builder
                ->withCostDefaults()
                ->withDeliveryEntitled(false, 401, 'Invalid API Key')
                ->withPaymentDefaults()
                ->withQrislyEntitled(false, 401, 'Invalid API Key'),
            'delivery_only' => $builder
                ->withCostUnauthorized(401, 'Invalid API Key')
                ->withDeliveryDefaults()
                ->withPaymentEntitled(false, 401, 'Invalid API Key')
                ->withQrislyEntitled(false, 401, 'Invalid API Key'),
            'qrisly_only' => $builder
                ->withPaymentEntitled(false, 401, 'Invalid API Key')
                ->withQrislyDefaults(),
            'payment_and_qrisly' => $builder
                ->withPaymentDefaults()
                ->withQrislyDefaults(),
            'komship' => $builder
                ->withCostDefaults()
                ->withDeliveryDefaults()
                ->withPaymentDefaults()
                ->withQrislyDefaults(),
            'quota_exhausted' => $builder
                ->withCostQuotaExhausted()
                ->withDeliveryEntitled(false, 401, 'Invalid API Key')
                ->withPaymentDefaults()
                ->withQrislyEntitled(false, 401, 'Invalid API Key'),
            default => throw new \InvalidArgumentException("Unknown vendor tier preset [{$tier}]."),
        };
    }

    public function withCostDefaults(): self
    {
        return $this
            ->withCostDestinations()
            ->withCostQuotes()
            ->withCostTracking();
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     */
    public function withCostDestinations(?array $rows = null): self
    {
        $rows ??= [
            [
                'id' => 17601,
                'label' => 'GAMBIR, GAMBIR, JAKARTA PUSAT, DKI JAKARTA, 10110',
                'province_name' => 'DKI JAKARTA',
                'city_name' => 'JAKARTA PUSAT',
                'district_name' => 'GAMBIR',
                'subdistrict_name' => 'GAMBIR',
                'zip_code' => '10110',
            ],
            [
                'id' => 17547,
                'label' => 'SENAYAN, KEBAYORAN BARU, JAKARTA SELATAN, DKI JAKARTA, 12190',
                'province_name' => 'DKI JAKARTA',
                'city_name' => 'JAKARTA SELATAN',
                'district_name' => 'KEBAYORAN BARU',
                'subdistrict_name' => 'SENAYAN',
                'zip_code' => '12190',
            ],
        ];

        $this->routes[self::COST_BASE.'/api/v1/destination/domestic-destination*'] = Http::response([
            'meta' => ['code' => 200, 'status' => 'success'],
            'data' => $rows,
        ], 200);

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     */
    public function withCostQuotes(?array $rows = null): self
    {
        $rows ??= [
            ['name' => 'JNE', 'code' => 'jne', 'service' => 'REG', 'description' => 'Layanan Reguler', 'cost' => 18000, 'etd' => '2-3'],
            ['name' => 'J&T', 'code' => 'jnt', 'service' => 'EZ', 'description' => 'Reguler', 'cost' => 17000, 'etd' => '2-3'],
            ['name' => 'POS', 'code' => 'pos', 'service' => 'Pos Reguler', 'description' => 'Pos Reguler', 'cost' => 16000, 'etd' => '3-4'],
            ['name' => 'SPX', 'code' => 'spx', 'service' => 'SPX Standard', 'description' => 'SPX Standard', 'cost' => 15000, 'etd' => '2-3'],
        ];

        $this->routes[self::COST_BASE.'/api/v1/calculate/domestic-cost*'] = Http::response([
            'meta' => ['code' => 200, 'status' => 'success'],
            'data' => $rows,
        ], 200);

        return $this;
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function withCostTracking(?array $data = null): self
    {
        $data ??= [
            'delivered' => false,
            'summary' => ['waybill_number' => 'JNE-STARTER-1', 'status' => 'ON PROCESS'],
            'delivery_status' => ['status' => 'ON PROCESS'],
            'manifest' => [
                [
                    'manifest_code' => '100',
                    'manifest_description' => 'Shipment received by JNE',
                    'manifest_date' => '2026-11-25',
                    'manifest_time' => '09:00',
                    'city_name' => 'Cirebon',
                ],
            ],
        ];

        $this->routes[self::COST_BASE.'/api/v1/track/waybill*'] = Http::response([
            'meta' => ['code' => 200, 'status' => 'success'],
            'data' => $data,
        ], 200);

        return $this;
    }

    public function withCostQuotaExhausted(int $status = 429, string $message = 'Daily request limit exceeded', bool $topLevelMessage = false): self
    {
        $this->clearPrefix(self::COST_BASE);
        $response = Http::response($this->errorBody($status, $message, $topLevelMessage), $status);

        $this->routes[self::COST_BASE.'/api/v1/destination/domestic-destination*'] = $response;
        $this->routes[self::COST_BASE.'/api/v1/calculate/domestic-cost*'] = $response;
        $this->routes[self::COST_BASE.'/api/v1/track/waybill*'] = $response;

        return $this;
    }

    public function withCostUnauthorized(int $status = 401, string $message = 'Invalid API Key', bool $topLevelMessage = false): self
    {
        $this->clearPrefix(self::COST_BASE);
        $response = Http::response($this->errorBody($status, $message, $topLevelMessage), $status);

        $this->routes[self::COST_BASE.'/api/v1/destination/domestic-destination*'] = $response;
        $this->routes[self::COST_BASE.'/api/v1/calculate/domestic-cost*'] = $response;
        $this->routes[self::COST_BASE.'/api/v1/track/waybill*'] = $response;

        return $this;
    }

    public function withCostOutage(int $status = 500, string $message = 'Upstream error'): self
    {
        $this->clearPrefix(self::COST_BASE);
        $response = Http::response($this->errorBody($status, $message, false), $status);

        $this->routes[self::COST_BASE.'/api/v1/destination/domestic-destination*'] = $response;
        $this->routes[self::COST_BASE.'/api/v1/calculate/domestic-cost*'] = $response;
        $this->routes[self::COST_BASE.'/api/v1/track/waybill*'] = $response;

        return $this;
    }

    public function withDeliveryDefaults(): self
    {
        return $this
            ->withDeliveryDestinations()
            ->withDeliveryTariffs()
            ->withDeliveryStoreOrderResponse()
            ->withDeliveryPickupResponse()
            ->withDeliveryPrintLabelResponse()
            ->withDeliveryDetailResponse()
            ->withDeliveryCancelResponse()
            ->withDeliveryTrackingResponse();
    }

    public function withDeliveryEntitled(bool $entitled = true, int $failStatus = 401, string $message = 'Invalid API Key', bool $topLevelMessage = false): self
    {
        $this->clearPrefix(self::DELIVERY_BASE);

        if ($entitled) {
            return $this->withDeliveryDefaults();
        }

        $response = Http::response($this->errorBody($failStatus, $message, $topLevelMessage), $failStatus);
        $this->routes[self::DELIVERY_BASE.'/tariff/api/v1/*'] = $response;
        $this->routes[self::DELIVERY_BASE.'/order/api/v1/*'] = $response;

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     */
    public function withDeliveryDestinations(?array $rows = null): self
    {
        $rows ??= [
            [
                'id' => 17601,
                'label' => 'GAMBIR, GAMBIR, JAKARTA PUSAT, DKI JAKARTA, 10110',
                'province_name' => 'DKI JAKARTA',
                'city_name' => 'JAKARTA PUSAT',
                'district_name' => 'GAMBIR',
                'subdistrict_name' => 'GAMBIR',
                'zip_code' => '10110',
            ],
        ];

        $this->routes[self::DELIVERY_BASE.'/tariff/api/v1/destination/search*'] = Http::response([
            'meta' => ['code' => 200, 'status' => 'success'],
            'data' => $rows,
        ], 200);

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>|null  $reguler
     * @param  list<array<string, mixed>>  $cargo
     * @param  list<array<string, mixed>>  $instant
     */
    public function withDeliveryTariffs(?array $reguler = null, array $cargo = [], array $instant = []): self
    {
        $reguler ??= [
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
        ];

        $this->routes[self::DELIVERY_BASE.'/tariff/api/v1/calculate*'] = Http::response([
            'meta' => ['code' => 200, 'status' => 'success'],
            'data' => [
                'calculate_reguler' => $reguler,
                'calculate_cargo' => $cargo,
                'calculate_instant' => $instant,
            ],
        ], 200);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function withDeliveryStoreOrderResponse(array $data = [], int $status = 200): self
    {
        $payload = array_merge(['order_id' => 20001, 'order_no' => 'RO-E2E-1'], $data);
        $this->routes[self::DELIVERY_BASE.'/order/api/v1/orders/store*'] = Http::response([
            'meta' => ['code' => 201, 'status' => 'success', 'message' => 'Success Create New Order'],
            'data' => $payload,
        ], $status);

        return $this;
    }

    public function withDeliveryStoreOrderTimeout(string $message = 'cURL error 28: Operation timed out'): self
    {
        $this->routes[self::DELIVERY_BASE.'/order/api/v1/orders/store*'] = static function () use ($message): never {
            throw new ConnectionException($message);
        };

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    public function withDeliveryPickupResponse(array $data = [], int $status = 200): self
    {
        $rows = $data !== [] ? $data : [[
            'status' => 'success',
            'order_no' => 'RO-E2E-1',
            'awb' => 'JNE-E2E-1',
        ]];

        $this->routes[self::DELIVERY_BASE.'/order/api/v1/pickup/request*'] = Http::response([
            'meta' => ['code' => 201, 'status' => 'success', 'message' => 'Success Request Pickup'],
            'data' => $rows,
        ], $status);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function withDeliveryPrintLabelResponse(array $data = [], int $status = 200, bool $pdfFetch404 = false): self
    {
        $payload = $data !== [] ? $data : ['path' => '/storage/label/RO-E2E-1.pdf'];

        $this->routes[self::DELIVERY_BASE.'/order/api/v1/orders/print-label*'] = Http::response([
            'meta' => ['code' => $status === 200 ? 200 : $status, 'status' => $status === 200 ? 'success' : 'error'],
            'data' => $payload,
        ], $status);

        $this->routes[self::DELIVERY_BASE.'/order/storage/label/*'] = $pdfFetch404
            ? Http::response('Not found', 404)
            : Http::response('%PDF-1.4 fake-label', 200, ['Content-Type' => 'application/pdf']);

        $this->routes[self::DELIVERY_BASE.'/storage/label/*'] = $pdfFetch404
            ? Http::response('Not found', 404)
            : Http::response('%PDF-1.4 fake-label', 200, ['Content-Type' => 'application/pdf']);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function withDeliveryDetailResponse(array $data = [], int $status = 200): self
    {
        $payload = array_merge([
            'order_no' => 'RO-E2E-1',
            'airway_bill' => 'JNE-E2E-1',
            'awb' => 'JNE-E2E-1',
            'last_status' => 'ON_PROCESS',
        ], $data);

        $this->routes[self::DELIVERY_BASE.'/order/api/v1/orders/detail*'] = Http::response([
            'meta' => ['code' => $status, 'status' => $status >= 400 ? 'failed' : 'success'],
            'data' => $payload,
        ], $status);

        return $this;
    }

    public function withDeliveryCancelResponse(int $status = 200): self
    {
        $this->routes[self::DELIVERY_BASE.'/order/api/v1/orders/cancel*'] = Http::response([
            'meta' => ['code' => $status, 'status' => $status >= 400 ? 'failed' : 'success'],
            'data' => [],
        ], $status);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function withDeliveryTrackingResponse(array $data = [], int $status = 200): self
    {
        $payload = array_merge([
            'airway_bill' => 'JNE-E2E-1',
            'last_status' => 'ON_PROCESS',
            'history' => [
                [
                    'desc' => 'Manifested at Cirebon Hub',
                    'date' => '2026-11-25 10:00:00',
                    'code' => '101',
                    'status' => 'ON_PROCESS',
                ],
            ],
        ], $data);

        $this->routes[self::DELIVERY_BASE.'/order/api/v1/orders/history-airway-bill*'] = Http::response([
            'meta' => ['code' => $status, 'status' => $status >= 400 ? 'failed' : 'success'],
            'data' => $payload,
        ], $status);

        return $this;
    }

    public function withPaymentDefaults(): self
    {
        return $this
            ->withPaymentMethods()
            ->withPaymentCreate()
            ->withPaymentStatus('KOMPAY-*', 'PAID', 100000)
            ->withPaymentCancel();
    }

    public function withPaymentEntitled(bool $entitled = true, int $failStatus = 401, string $message = 'Invalid API key', bool $topLevelMessage = false): self
    {
        $this->clearPrefix(self::PAYMENT_BASE);

        if ($entitled) {
            return $this->withPaymentDefaults();
        }

        $this->routes[self::PAYMENT_BASE.'/api/v1/user/*'] = Http::response(
            $this->errorBody($failStatus, $message, $topLevelMessage),
            $failStatus,
        );

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>|null  $methods
     */
    public function withPaymentMethods(?array $methods = null): self
    {
        $methods ??= [
            [
                'payment_type' => 'va',
                'display_name' => 'Bank Central Asia',
                'bank_code' => 'BCA',
                'logo_url' => 'https://example.test/bca.png',
                'min_amount' => 10000,
                'max_amount' => 999999999,
            ],
            [
                'payment_type' => 'qris',
                'display_name' => 'QRIS',
                'bank_code' => '',
                'logo_url' => 'https://example.test/qris.png',
                'min_amount' => 10000,
                'max_amount' => 10000000,
            ],
        ];

        $this->routes[self::PAYMENT_BASE.'/api/v1/user/methods'] = Http::response([
            'meta' => ['code' => 200, 'status' => 'success'],
            'data' => $methods,
        ], 200);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function withPaymentCreate(array $data = [], int $status = 200): self
    {
        $this->routes[self::PAYMENT_BASE.'/api/v1/user/payment/create'] = static function ($request) use ($data, $status) {
            if ($status >= 400) {
                return Http::response(['success' => false, 'message' => 'Payment create error'], $status);
            }

            $body = $request->data();
            $isQris = ($body['payment_type'] ?? '') === 'qris';

            $defaults = $isQris
                ? [
                    'payment_id' => 'KOMPAY-QRIS-1',
                    'qris_string' => '00020101021226640013ID.CO.KOMERCE.QRIS',
                    'expiry_date' => '2026-12-31 23:59:59',
                    'amount' => (int) ($body['amount'] ?? 50000),
                ]
                : [
                    'payment_id' => 'KOMPAY-VA-1',
                    'virtual_account_number' => '8808001234567890',
                    'bank_code' => (string) ($body['channel_code'] ?? 'BCA'),
                    'expiry_date' => '2026-12-31 23:59:59',
                    'amount' => (int) ($body['amount'] ?? 100000),
                ];

            return Http::response([
                'success' => true,
                'data' => array_merge($defaults, $data),
            ], $status);
        };

        return $this;
    }

    public function withPaymentStatus(string $paymentIdPattern = '*', string $paymentStatus = 'PAID', int $amount = 100000, int $httpStatus = 200): self
    {
        $pattern = self::PAYMENT_BASE.'/api/v1/user/payment/status/'.$paymentIdPattern;

        if ($httpStatus >= 400) {
            $this->routes[$pattern] = Http::response($this->errorBody($httpStatus, 'Payment status error', false), $httpStatus);

            return $this;
        }

        $this->routes[$pattern] = Http::response([
            'success' => true,
            'data' => [
                'payment_id' => str_contains($paymentIdPattern, '*') ? 'KOMPAY-VA-1' : $paymentIdPattern,
                'status' => $paymentStatus,
                'amount' => $amount,
            ],
        ], 200);

        return $this;
    }

    public function withPaymentCancel(int $httpStatus = 200): self
    {
        $this->routes[self::PAYMENT_BASE.'/api/v1/user/payment/cancel'] = Http::response(
            $httpStatus >= 400
                ? $this->errorBody($httpStatus, 'Cancel failed', false)
                : ['success' => true, 'data' => ['status' => 'CANCELLED']],
            $httpStatus,
        );

        return $this;
    }

    public function withPaymentOutage(int $status = 500): self
    {
        $this->clearPrefix(self::PAYMENT_BASE);
        $response = Http::response($this->errorBody($status, 'Service Unavailable', false), $status);
        $this->routes[self::PAYMENT_BASE.'/api/v1/user/*'] = $response;

        return $this;
    }

    public function withQrislyDefaults(): self
    {
        return $this
            ->withQrislyGenerate()
            ->withQrislyStatus('*', 'paid', 50001);
    }

    public function withQrislyEntitled(bool $entitled = true, int $failStatus = 401, string $message = 'Invalid API Key', bool $topLevelMessage = false): self
    {
        $this->clearPrefix(self::QRISLY_BASE);

        if ($entitled) {
            return $this->withQrislyDefaults();
        }

        $this->routes[self::QRISLY_BASE.'/api/v1/qrisly/*'] = Http::response(
            $this->errorBody($failStatus, $message, $topLevelMessage),
            $failStatus,
        );

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function withQrislyGenerate(array $data = [], int $status = 200): self
    {
        $this->routes[self::QRISLY_BASE.'/api/v1/qrisly/generate-qris'] = static function ($request) use ($data, $status) {
            if ($status >= 400) {
                return Http::response([
                    'meta' => ['code' => $status, 'status' => 'failed', 'message' => 'QRISLY error'],
                    'data' => [],
                ], $status);
            }

            $body = $request->data();
            $amount = (int) ($body['amount'] ?? 50000);

            return Http::response([
                'success' => true,
                'data' => array_merge([
                    'history_id' => 9001,
                    'qris_string' => '000201010212QRISLY-GENERATED',
                    'final_amount' => $amount + 1,
                    'expiry_time' => '2026-12-31 23:00:00',
                ], $data),
            ], $status);
        };

        return $this;
    }

    public function withQrislyStatus(string|int $historyIdPattern = '*', string $paymentStatus = 'paid', int $amount = 50001, int $httpStatus = 200): self
    {
        $pattern = self::QRISLY_BASE.'/api/v1/qrisly/payment-status/'.$historyIdPattern;

        if ($httpStatus >= 400) {
            $this->routes[$pattern] = Http::response($this->errorBody($httpStatus, 'QRISLY status failed', false), $httpStatus);

            return $this;
        }

        $this->routes[$pattern] = Http::response([
            'data' => [
                'history_id' => is_numeric($historyIdPattern) ? (int) $historyIdPattern : 9001,
                'payment_status' => $paymentStatus,
                'amount' => $amount,
            ],
        ], 200);

        return $this;
    }

    public function withRoute(string $urlPattern, mixed $response): self
    {
        $this->routes[$urlPattern] = $response;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function apply(): void
    {
        $sorted = $this->routes;
        uksort($sorted, static function (string $a, string $b): int {
            $wildcardsA = substr_count($a, '*');
            $wildcardsB = substr_count($b, '*');

            if ($wildcardsA !== $wildcardsB) {
                return $wildcardsA <=> $wildcardsB;
            }

            return strlen($b) <=> strlen($a);
        });

        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake($sorted);
    }

    private function clearPrefix(string $prefix): void
    {
        foreach (array_keys($this->routes) as $pattern) {
            if (str_starts_with($pattern, $prefix)) {
                unset($this->routes[$pattern]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function errorBody(int $status, string $message, bool $topLevelMessage): array
    {
        if ($topLevelMessage) {
            return ['message' => $message];
        }

        return [
            'meta' => [
                'message' => $message,
                'code' => $status,
                'status' => 'failed',
            ],
            'data' => [],
        ];
    }
}

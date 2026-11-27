<?php

declare(strict_types=1);

namespace Tests\Feature\QaRegression;

use App\Actions\Account\ConfirmOrderReceived;
use App\Http\Responses\CpanelStaffLoginResponse;
use App\Livewire\Shopper\Dashboard\StatCards;
use App\Livewire\Shopper\Pages\FinanceReports;
use App\Livewire\Shopper\Pages\ManualPaymentProofs;
use App\Livewire\Shopper\Pages\OrderReturns;
use App\Livewire\Shopper\Pages\PaymentDisputes;
use App\Models\Category;
use App\Models\Collection;
use App\Models\OrderPaymentProof;
use App\Models\OrderReturnRequest;
use App\Models\OrderShipment;
use App\Models\PaymentDispute;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Shopper\Core\Enum\CollectionType;
use Shopper\Core\Enum\OrderStatus;
use Shopper\Core\Enum\PaymentStatus;
use Shopper\Core\Enum\ShippingStatus;
use Shopper\Core\Models\Currency;
use Shopper\Core\Models\Inventory;
use Shopper\Core\Models\Order;
use Shopper\Core\Models\PaymentMethod;
use Shopper\Core\Models\Price;
use Shopper\Core\Models\Review;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StandaloneAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_connection_enables_memory_temp_store_pragma(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-only pragma check.');
        }

        $rows = DB::select('PRAGMA temp_store');
        $value = (int) ($rows[0]->temp_store ?? -1);

        $this->assertSame(2, $value, 'SQLite temp_store pragma should be MEMORY (2).');
    }

    public function test_mobile_legal_content_is_finalized_without_draft_or_todos(): void
    {
        $raw = (string) file_get_contents(base_path('mobile/lib/legal-content.json'));
        /** @var array{draft?: bool} $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($decoded['draft'] ?? true);
        $this->assertStringNotContainsString('[TODO', $raw);
    }

    public function test_health_endpoint_emits_cors_headers_for_expo_web(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:8081'])
            ->get('/up')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_guest_can_verify_signed_email_link_without_web_session_while_other_user_session_is_blocked(): void
    {
        $mobileUser = User::factory()->unverified()->create();
        $otherUser = User::factory()->create();

        Event::fake();

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $mobileUser->id, 'hash' => sha1($mobileUser->email)],
        );

        // Logged in as another user must still be rejected.
        $this->actingAs($otherUser)->get($url)->assertForbidden();
        $this->assertFalse($mobileUser->fresh()->hasVerifiedEmail());

        // Guest browser (e.g. mobile user tapping link from mail app) succeeds.
        auth()->logout();
        $this->get($url)
            ->assertRedirect(route('login', ['verified' => 1]));

        Event::assertDispatched(Verified::class);
        $this->assertTrue($mobileUser->fresh()->hasVerifiedEmail());

        app()->setLocale('id');
        $this->assertSame(
            'Verifikasi alamat emailmu terlebih dahulu sebelum melanjutkan.',
            __('Your email address is not verified.'),
        );
    }

    public function test_mobile_order_detail_includes_working_signed_invoice_url_and_web_review_report_hides_review(): void
    {
        $customer = User::factory()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);

        $response = $this->actingAs($customer, 'sanctum')
            ->getJson("/api/v1/orders/{$order->number}")
            ->assertOk();

        $invoiceUrl = (string) $response->json('data.invoice_url');
        $this->assertNotSame('', $invoiceUrl);

        // Accessible via signed URL without browser cookie session.
        $this->get($invoiceUrl)->assertOk()->assertSee($order->number);

        // Tampered signature must fail with 403.
        $this->get($invoiceUrl.'tampered')->assertForbidden();

        // Web review report endpoint hides an approved review.
        $product = Product::factory()->create(['is_visible' => true, 'published_at' => now()->subDay()]);
        $reviewer = User::factory()->create();
        $review = Review::query()->create([
            'reviewrateable_type' => $product->getMorphClass(),
            'reviewrateable_id' => $product->id,
            'author_type' => $reviewer->getMorphClass(),
            'author_id' => $reviewer->id,
            'rating' => 5,
            'title' => 'Mantap',
            'content' => 'Ulasan awal',
            'approved' => true,
        ]);

        $this->from(route('shop.product', $product->slug))
            ->actingAs($customer)
            ->post(route('shop.reviews.report', $review), ['reason' => 'Spam atau iklan'])
            ->assertRedirect(route('shop.product', $product->slug))
            ->assertSessionHas('success');

        $this->assertFalse((bool) $review->fresh()->approved);
    }

    public function test_web_404_page_provides_storefront_navigation_links(): void
    {
        $this->get('/shop/produk-tidak-ada-999')
            ->assertNotFound()
            ->assertSee('Halaman atau produk tidak ditemukan')
            ->assertSee(url('/shop'), false);
    }

    public function test_confirm_order_received_on_delivered_cod_order_is_idempotent(): void
    {
        $customer = User::factory()->create();
        $cod = PaymentMethod::factory()->create(['slug' => 'cod', 'title' => 'Bayar di Tempat (COD)']);
        $inventory = Inventory::factory()->create();

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'payment_method_id' => $cod->id,
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Pending,
            'shipping_status' => ShippingStatus::Delivered,
        ]);

        OrderShipment::query()->create([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'carrier_code' => 'manual',
            'service_code' => 'Reguler',
            'status' => 'delivered',
            'awb' => 'MANUAL-COD-1',
        ]);

        $countBefore = $customer->notifications()->count();
        $result = resolve(ConfirmOrderReceived::class)->handle($order);

        $this->assertSame(ShippingStatus::Delivered, $result->shipping_status);
        $this->assertSame(OrderStatus::Processing, $result->status);
        $this->assertSame($countBefore, $customer->notifications()->count());
    }

    public function test_unverified_customer_without_orders_can_delete_profile(): void
    {
        $user = User::factory()->unverified()->create(['password' => 'secret123']);

        $this->actingAs($user)
            ->delete('/settings/profile', ['password' => 'secret123'])
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_category_collection_price_sorting_and_cpanel_standalone_label_and_login_intended_work(): void
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'IDR'],
            ['name' => 'Indonesian Rupiah', 'symbol' => 'Rp', 'format' => 'Rp {price}', 'exchange_rate' => 1, 'is_enabled' => true],
        );

        $category = Category::factory()->create(['name' => 'Smartphone', 'slug' => 'smartphone-test', 'is_enabled' => true]);
        $collection = Collection::factory()->create(['name' => 'Promo', 'slug' => 'promo-test', 'type' => CollectionType::Manual, 'published_at' => now()->subDay()]);

        $cheap = Product::factory()->create(['name' => 'Produk Murah', 'slug' => 'produk-murah', 'is_visible' => true, 'published_at' => now()->subDay()]);
        $expensive = Product::factory()->create(['name' => 'Produk Mahal', 'slug' => 'produk-mahal', 'is_visible' => true, 'published_at' => now()->subDay()]);

        foreach ([[$cheap, 100000], [$expensive, 500000]] as [$prod, $amount]) {
            Price::query()->create([
                'priceable_type' => $prod->getMorphClass(),
                'priceable_id' => $prod->id,
                'currency_id' => $currency->id,
                'amount' => $amount,
            ]);
            $prod->categories()->attach($category->id);
            $prod->collections()->attach($collection->id);
        }

        $this->get(route('shop.category', ['category' => $category->slug, 'sort' => 'price_asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('products.data.0.slug', 'produk-murah'));

        $this->get(route('shop.collection', ['collection' => $collection->slug, 'sort' => 'price_desc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('products.data.0.slug', 'produk-mahal'));

        // Manual standalone shipment renders thermal label HTML when no shipping provider is active.
        config()->set('shopper.shipping.provider', 'none');
        $this->configureShopperCpanel();
        $admin = User::factory()->create();
        $adminRole = Role::query()->firstOrCreate([
            'name' => config('shopper.admin.roles.admin'),
            'guard_name' => 'web',
        ]);
        $admin->assignRole($adminRole);

        $inventory = Inventory::factory()->create();
        $manualOrder = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        OrderShipment::query()->create([
            'order_id' => $manualOrder->id,
            'inventory_id' => $inventory->id,
            'driver' => 'manual',
            'carrier_code' => 'manual',
            'service_code' => 'Reguler',
            'status' => 'labeled',
            'awb' => 'MANUAL-001',
        ]);

        $this->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $manualOrder))
            ->assertOk()
            ->assertSee('MANUAL-001');

        // Provider-owned shipment with disabled provider still returns validation error flash.
        $providerOrder = Order::factory()->create([
            'currency_code' => 'IDR',
            'status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);
        OrderShipment::query()->create([
            'order_id' => $providerOrder->id,
            'inventory_id' => $inventory->id,
            'driver' => 'acme',
            'carrier_code' => 'acme-express',
            'service_code' => 'REG',
            'status' => 'labeled',
            'awb' => 'ACME-001',
        ]);

        $this->from(route('shopper.orders.detail', $providerOrder))
            ->actingAs($admin)
            ->get(route('shopper.orders.fulfillment.print-label', $providerOrder))
            ->assertSessionHasErrors('label');

        // CpanelStaffLoginResponse ignores customer /dashboard url.intended and honors /cpanel/*.
        $request = request();
        $request->setLaravelSession(app('session.store'));
        auth()->login($admin);

        session()->put('url.intended', url('/dashboard'));
        $redirect1 = resolve(CpanelStaffLoginResponse::class)->toResponse($request);
        $this->assertSame(route('shopper.dashboard'), $redirect1->getTargetUrl());

        session()->put('url.intended', url('/cpanel/orders'));
        $redirect2 = resolve(CpanelStaffLoginResponse::class)->toResponse($request);
        $this->assertSame(url('/cpanel/orders'), $redirect2->getTargetUrl());
    }

    public function test_upgraded_cpanel_addon_pages_render_native_chrome_and_table_actions(): void
    {
        $this->configureShopperCpanel();
        $admin = User::factory()->create();
        $adminRole = Role::query()->firstOrCreate([
            'name' => config('shopper.admin.roles.admin'),
            'guard_name' => 'web',
        ]);
        $admin->assignRole($adminRole);

        $customer = User::factory()->create();
        $inventory = Inventory::factory()->create(['is_default' => true]);

        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'currency_code' => 'IDR',
            'price_amount' => 150000,
            'status' => OrderStatus::New,
            'payment_status' => PaymentStatus::Pending,
            'shipping_status' => ShippingStatus::Unfulfilled,
        ]);

        $proof = OrderPaymentProof::query()->create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'disk' => 'local',
            'path' => 'payment-proofs/sample.jpg',
            'original_name' => 'sample.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'sender_name' => 'Budi Santoso',
            'sender_bank' => 'BCA',
            'transfer_amount' => 150000,
            'status' => OrderPaymentProof::STATUS_PENDING,
        ]);

        $returnRequest = OrderReturnRequest::query()->create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'status' => OrderReturnRequest::STATUS_APPROVED,
            'reason' => 'damaged',
            'items' => [],
        ]);

        $dispute = PaymentDispute::query()->create([
            'order_id' => $order->id,
            'driver' => 'manual',
            'type' => PaymentDispute::TYPE_AMOUNT_MISMATCH,
            'status' => PaymentDispute::STATUS_OPEN,
            'reference' => 'DISP-UI-001',
            'amount' => 150000,
            'expected_amount' => 150000,
            'currency_code' => 'IDR',
        ]);

        $this->actingAs($admin);

        foreach ([
            route('shopper.dashboard'),
            route('shopper.orders.payment-proofs'),
            route('shopper.orders.returns'),
            route('shopper.orders.disputes'),
            route('shopper.orders.allocation-logs'),
            route('shopper.reports.finance'),
            route('shopper.orders.detail', $order),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('css/cpanel-addon.css', false);
        }

        Livewire::actingAs($admin)
            ->test(StatCards::class)
            ->assertSet('period', '30d')
            ->set('period', '7d')
            ->assertSet('period', '7d');

        Livewire::actingAs($admin)
            ->test(FinanceReports::class)
            ->assertSet('activeTab', 'margin')
            ->set('activeTab', 'ppn')
            ->assertSee('PPN')
            ->set('activeTab', 'settlement')
            ->assertSee('COD');

        Livewire::actingAs($admin)
            ->test(ManualPaymentProofs::class)
            ->assertCanSeeTableRecords([$proof])
            ->callTableAction('approveProof', $proof, data: [
                'verified_amount' => 150000,
                'bank_reference' => 'MUTASI-UI-1',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(OrderPaymentProof::STATUS_APPROVED, $proof->fresh()->status);

        Livewire::actingAs($admin)
            ->test(OrderReturns::class)
            ->set('statusFilter', 'approved')
            ->assertCanSeeTableRecords([$returnRequest])
            ->callTableAction('restockReturn', $returnRequest, data: [
                'inventory_id' => $inventory->id,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(OrderReturnRequest::STATUS_RESTOCKED, $returnRequest->fresh()->status);

        Livewire::actingAs($admin)
            ->test(PaymentDisputes::class)
            ->assertCanSeeTableRecords([$dispute])
            ->callTableAction('dismissAction', $dispute, data: [
                'notes' => 'Ditutup via UI QA test.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(PaymentDispute::STATUS_DISMISSED, $dispute->fresh()->status);
    }
}

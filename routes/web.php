<?php

declare(strict_types=1);

use App\Http\Controllers\Account\AddressController;
use App\Http\Controllers\Account\ConfirmOrderReceivedController;
use App\Http\Controllers\Account\NotificationController;
use App\Http\Controllers\Account\OrderController as AccountOrderController;
use App\Http\Controllers\Account\RetryOrderPaymentController;
use App\Http\Controllers\Account\SyncOrderPaymentController;
use App\Http\Controllers\Account\TrackShipmentController;
use App\Http\Controllers\Cpanel\OverrideAllocationController;
use App\Http\Controllers\Cpanel\PrintShipmentLabelController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CartCouponController;
use App\Http\Controllers\Shop\CategoryController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\CheckoutSuccessController;
use App\Http\Controllers\Shop\CollectionController;
use App\Http\Controllers\Shop\DestinationSearchController;
use App\Http\Controllers\Shop\HomeController;
use App\Http\Controllers\Shop\ProductController;
use App\Http\Controllers\Shop\ProductReviewController;
use App\Http\Controllers\Shop\SearchController;
use App\Http\Controllers\Shop\ZoneController;
use App\Http\Controllers\StripeWebhookController;
use App\Support\LegalContent;
use Illuminate\Support\Facades\Route;

// Storefront
Route::get('/', HomeController::class)->name('home');

// Public pages the store listings link to (privacy policy, terms, support, account deletion).
Route::get('legal/{slug}', [LegalPageController::class, 'show'])
    ->whereIn('slug', LegalContent::SLUGS)
    ->name('legal.show');
Route::get('bantuan', [LegalPageController::class, 'support'])->name('legal.support');
Route::get('hapus-akun', [LegalPageController::class, 'accountDeletion'])->name('legal.account-deletion');

// PWA assets & fallback routes
Route::get('manifest.json', function () {
    return response()->file(public_path('build/manifest.webmanifest'), [
        'Content-Type' => 'application/manifest+json',
    ]);
});
Route::get('manifest.webmanifest', function () {
    return response()->file(public_path('build/manifest.webmanifest'), [
        'Content-Type' => 'application/manifest+json',
    ]);
});
Route::get('sw.js', function () {
    return response()->file(public_path('build/sw.js'), [
        'Content-Type' => 'application/javascript',
        'Service-Worker-Allowed' => '/',
    ]);
});
Route::get('shop', [ProductController::class, 'index'])->name('shop.index');
Route::get('shop/{product:slug}', [ProductController::class, 'show'])->name('shop.product');
Route::post('shop/{product:slug}/reviews', [ProductReviewController::class, 'store'])
    ->middleware(['auth', 'verified', 'throttle:10,1'])
    ->name('shop.product.reviews.store');
Route::get('categories', [CategoryController::class, 'index'])->name('shop.categories');
Route::get('categories/{category:slug}', [CategoryController::class, 'show'])->name('shop.category');
Route::get('collections/{collection:slug}', [CollectionController::class, 'show'])->name('shop.collection');
Route::get('search', SearchController::class)->middleware('throttle:30,1')->name('shop.search');

// Cart
Route::get('cart', [CartController::class, 'index'])->name('shop.cart');
Route::middleware('throttle:60,1')->group(function (): void {
    Route::post('cart', [CartController::class, 'add'])->name('shop.cart.add');
    Route::post('cart/coupon', [CartCouponController::class, 'store'])->middleware('throttle:coupon')->name('shop.cart.coupon.store');
    Route::delete('cart/coupon', [CartCouponController::class, 'destroy'])->name('shop.cart.coupon.destroy');
    Route::patch('cart/{line}', [CartController::class, 'update'])->name('shop.cart.update');
    Route::delete('cart/{line}', [CartController::class, 'destroy'])->name('shop.cart.destroy');
    Route::delete('cart', [CartController::class, 'clear'])->name('shop.cart.clear');
});

// Zone
Route::patch('zone', [ZoneController::class, 'update'])->middleware('throttle:30,1')->name('shop.zone.update');

// Checkout
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('checkout', [CheckoutController::class, 'index'])->name('shop.checkout.index');
    Route::get('checkout/destinations', DestinationSearchController::class)
        ->middleware('throttle:checkout-destinations')
        ->name('shop.checkout.destinations');
    Route::post('checkout/shipping-address', [CheckoutController::class, 'saveShippingAddress'])->middleware('throttle:checkout-quote')->name('shop.checkout.shipping-address');
    Route::post('checkout/shipping-option', [CheckoutController::class, 'saveShippingOption'])->middleware('throttle:checkout-quote')->name('shop.checkout.shipping-option');
    Route::post('checkout/prepare-payment', [CheckoutController::class, 'preparePayment'])->middleware('throttle:checkout-payment')->name('shop.checkout.prepare-payment');
    Route::post('checkout/place-order', [CheckoutController::class, 'placeOrder'])->middleware('throttle:checkout-payment')->name('shop.checkout.place-order');
    // Return URL of confirm-first payment drivers (ConfirmsBeforeOrder).
    Route::get('checkout/confirm/{driver}/return', [CheckoutController::class, 'confirmReturn'])
        ->where('driver', '[a-z0-9_-]+')
        ->name('shop.checkout.confirm-return');
    // Return URL of releases before ConfirmsBeforeOrder (in-flight Stripe payments).
    Route::get('checkout/stripe-return', [CheckoutController::class, 'stripeReturn'])->name('shop.checkout.stripe-return');
    // Links to the removed Stripe payment page; redirects to the order's payment status.
    Route::get('checkout/payment/{number}', [CheckoutController::class, 'legacyPaymentPage'])->name('shop.checkout.stripe');
    Route::get('checkout/success/{order}', CheckoutSuccessController::class)->name('shop.checkout.success');
});

Route::get('payment/return/mobile/{order:number}', static function (\Shopper\Core\Models\Order $order) {
    $deepLink = 'oceanmall://order/'.rawurlencode((string) $order->number);

    return response(
        '<!doctype html><html><head><meta charset="utf-8"><title>Kembali ke OceanMall</title>'
        .'<meta http-equiv="refresh" content="0;url='.e($deepLink).'"></head>'
        .'<body style="font-family:system-ui,sans-serif;padding:24px;text-align:center;">'
        .'<p>Mengalihkan kembali ke aplikasi OceanMall...</p>'
        .'<p><a href="'.e($deepLink).'">Buka Pesanan '.e((string) $order->number).'</a></p>'
        .'</body></html>',
        200,
        ['Content-Type' => 'text/html; charset=UTF-8'],
    );
})->name('payment.return.mobile');

// Webhooks
Route::post('webhooks/stripe', StripeWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.stripe');

// Provider webhooks (e.g. webhooks/komerce/*) are registered by each
// provider's own service provider, see app/Integrations/*.

// Account
Route::middleware(['auth', 'verified'])->prefix('account')->name('account.')->group(function (): void {
    Route::get('orders', [AccountOrderController::class, 'index'])->name('orders');
    Route::get('orders/{order}', [AccountOrderController::class, 'show'])->name('orders.show');
    Route::get('orders/{order}/invoice', [\App\Http\Controllers\OrderInvoiceController::class, 'customer'])->name('orders.invoice');
    Route::post('orders/{order}/retry-payment', RetryOrderPaymentController::class)
        ->middleware('throttle:checkout-payment')
        ->name('orders.retry-payment');
    Route::post('orders/{order}/sync-payment', SyncOrderPaymentController::class)
        ->middleware('throttle:12,1')
        ->name('orders.sync-payment');
    Route::post('orders/{order}/shipments/{shipment}/track', TrackShipmentController::class)
        ->middleware('throttle:shipment-track')
        ->name('orders.shipments.track');
    Route::post('orders/{order}/confirm-received', ConfirmOrderReceivedController::class)
        ->name('orders.confirm-received');
    Route::post('orders/{order}/cancel', [\App\Http\Controllers\Account\OrderActionController::class, 'cancel'])
        ->middleware('throttle:10,1')
        ->name('orders.cancel');
    Route::post('orders/{order}/returns', [\App\Http\Controllers\Account\OrderActionController::class, 'storeReturn'])
        ->middleware('throttle:10,1')
        ->name('orders.returns.store');
    Route::post('orders/{order}/payment-proof', [\App\Http\Controllers\Account\OrderActionController::class, 'uploadProof'])
        ->middleware('throttle:10,1')
        ->name('orders.payment-proof.store');
    Route::get('orders/{order}/payment-proofs/{proof}', [\App\Http\Controllers\Account\OrderActionController::class, 'showProof'])
        ->name('orders.payment-proofs.show');
    Route::get('orders/{order}/returns/{returnRequest}/evidence/{index}', [\App\Http\Controllers\Account\OrderActionController::class, 'showReturnEvidence'])
        ->whereNumber('index')
        ->name('orders.returns.evidence');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->whereUuid('notification')
        ->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.read-all');

    Route::get('addresses', [AddressController::class, 'index'])->name('addresses');
    Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
    Route::patch('addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
    Route::delete('addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');
    Route::patch('addresses/{address}/default-shipping', [AddressController::class, 'setDefaultShipping'])->name('addresses.default-shipping');
    Route::patch('addresses/{address}/default-billing', [AddressController::class, 'setDefaultBilling'])->name('addresses.default-billing');
});

// Warehouse fulfillment under Shopper /cpanel only — staff ops, not customer-facing.
Route::middleware(['auth', 'verified'])
    ->prefix(config('shopper.admin.prefix', 'cpanel'))
    ->group(function (): void {
        Route::get('orders/{order}/fulfillment/label', PrintShipmentLabelController::class)
            ->name('shopper.orders.fulfillment.print-label');
        Route::post('orders/{order}/fulfillment/override-allocation', OverrideAllocationController::class)
            ->name('shopper.orders.fulfillment.override-allocation');
        Route::get('orders/{order}/packing-slip', [\App\Http\Controllers\Cpanel\WarehouseDocsController::class, 'packingSlip'])
            ->name('shopper.orders.warehouse.packing-slip');
        Route::get('orders/warehouse/pick-list', [\App\Http\Controllers\Cpanel\WarehouseDocsController::class, 'pickList'])
            ->name('shopper.orders.warehouse.pick-list');
        Route::get('orders/warehouse/bulk-labels', [\App\Http\Controllers\Cpanel\WarehouseDocsController::class, 'bulkLabels'])
            ->name('shopper.orders.warehouse.bulk-labels');
        Route::get('orders/{order}/invoice', [\App\Http\Controllers\OrderInvoiceController::class, 'staff'])
            ->name('cpanel.orders.invoice');
        Route::get('reports/finance/export', \App\Http\Controllers\Cpanel\FinanceExportController::class)
            ->name('cpanel.reports.finance.export');
        Route::get('orders/{order}/payment-proofs/{proof}', [\App\Http\Controllers\Cpanel\OrderFileController::class, 'showProof'])
            ->name('shopper.orders.payment-proofs.show');
        Route::get('orders/{order}/returns/{returnRequest}/evidence/{index}', [\App\Http\Controllers\Cpanel\OrderFileController::class, 'showReturnEvidence'])
            ->whereNumber('index')
            ->name('shopper.orders.returns.evidence');
    });

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';

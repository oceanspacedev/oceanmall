<?php

declare(strict_types=1);

namespace Tests\Feature\E2E\VendorPackageTier\Tier3;

use App\CheckoutSession;
use App\Support\CustomerCheckoutState;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Shopper\Cart\Models\CartLine;
use Shopper\Core\Models\Order;
use Tests\Feature\E2E\VendorPackageTier\Support\VendorPackageTierTestCase;

final class GuestCartAdoptionAndVoidingTest extends VendorPackageTierTestCase
{
    public function test_web_guest_cart_adoption_and_cart_mutations_void_picked_shipping_and_reject_stale_plan(): void
    {
        $this->applyTier('starter');
        $productA = $this->fixtures->createStockedProduct(100000, 10, 500);
        $productB = $this->fixtures->createStockedProduct(50000, 10, 500);

        // 1. Authenticated Web cart mutations (add/update/remove) void picked shipping & allocation
        ['user' => $webUser, 'cart' => $cart] = $this->webCustomerWithCart($productA, 1);
        $this->webSaveShippingAddress($webUser)->assertRedirect(route('shop.checkout.index'));
        $this->webLoadCheckout($webUser, 2)->assertOk();
        $this->webPickShippingOption($webUser, 'jne:REG')->assertRedirect(route('shop.checkout.index'));

        $this->webAddToCart($productB, 1, $webUser)->assertRedirect();
        $this->assertNull(session()->get(CheckoutSession::KEY));

        $this->webLoadCheckout($webUser, 2)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('selectedDeliveryOption', null)
                ->where('shippingAddress.destination_id', '17601')
            );

        // Re-pick shipping, then update cart line quantity -> voids picked shipping
        $this->webPickShippingOption($webUser, 'jne:REG')->assertRedirect(route('shop.checkout.index'));
        $lineB = CartLine::query()->where('cart_id', $cart->id)->where('purchasable_id', $productB->id)->firstOrFail();
        $this->webUpdateCartLine($lineB->id, 2, $webUser)->assertRedirect();
        $this->assertNull(session()->get(CheckoutSession::KEY));

        // Re-pick shipping, then remove line -> voids picked shipping
        $this->webLoadCheckout($webUser, 2)->assertOk();
        $this->webPickShippingOption($webUser, 'jne:REG')->assertRedirect(route('shop.checkout.index'));
        $this->webRemoveCartLine($lineB->id, $webUser)->assertRedirect();
        $this->assertNull(session()->get(CheckoutSession::KEY));

        // 2. Behind-the-scenes cart mutation leaves stale AllocationPlan -> CreateOrder refuses placement
        $this->webLoadCheckout($webUser, 2)->assertOk();
        $this->webPickShippingOption($webUser, 'jne:REG')->assertRedirect(route('shop.checkout.index'));
        CartLine::query()->where('cart_id', $cart->id)->update(['quantity' => 3]);

        $ordersBefore = Order::query()->count();
        $this->webPlaceOrder($webUser, $this->fixtures->paymentMethods['bca']->id)
            ->assertRedirect()
            ->assertSessionHasErrors();
        $this->assertSame($ordersBefore, Order::query()->count());

        // 3. Web Guest Login Cart Adoption: customer with saved CustomerCheckoutState quote logs in with guest cart
        $returningCustomer = $this->apiCustomerWithCart($productA, 1);
        $this->apiSaveShippingAddress($returningCustomer)->assertOk();
        $this->apiPickShippingOption($returningCustomer, 'jne:REG')->assertOk();
        $this->assertNotNull(data_get(resolve(CustomerCheckoutState::class)->get($returningCustomer), 'shipping_option.0'));

        // Switch to guest web browser session and add productB
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->flushSession();
        $this->withSession(['zone_country_code' => 'ID']);

        $this->webAddToCart($productB, 1)->assertRedirect();
        $this->post(route('login.store'), [
            'email' => $returningCustomer->email,
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($returningCustomer);
        $this->assertNull(data_get(resolve(CustomerCheckoutState::class)->get($returningCustomer), 'shipping_option'));
        $this->assertNull(data_get(resolve(CustomerCheckoutState::class)->get($returningCustomer), 'allocation_plan'));
        $this->assertNotNull(data_get(resolve(CustomerCheckoutState::class)->get($returningCustomer), 'shipping_address'));

        $this->apiLoadCheckout($returningCustomer)
            ->assertOk()
            ->assertJsonCount(2, 'data.cart.lines')
            ->assertJsonPath('data.shipping_option', null);
    }

    public function test_api_guest_cart_adoption_via_cart_token_and_mutations_void_quote_and_reject_stale_plan(): void
    {
        $this->applyTier('starter');
        $productA = $this->fixtures->createStockedProduct(100000, 10, 500);
        $productB = $this->fixtures->createStockedProduct(60000, 10, 500);

        $apiUser = $this->apiCustomerWithCart($productA, 1);
        $this->apiSaveShippingAddress($apiUser)->assertOk();
        $this->apiPickShippingOption($apiUser, 'jne:REG')
            ->assertOk()
            ->assertJsonPath('data.shipping_option.service_code', 'jne:REG');

        // Guest adds item using X-Cart-Token passed per request (never flushHeaders)
        $guestToken = 'guest-token-pairwise-e2e';
        $this->apiGuestCartAdd($guestToken, $productB, 1)->assertOk();

        // Login with X-Cart-Token adopts guest cart and voids picked shipping/allocation
        $this->apiLogin($apiUser->email, 'password', $guestToken)->assertOk();

        $checkoutAfterAdopt = $this->apiLoadCheckout($apiUser)->assertOk();
        $this->assertCount(2, $checkoutAfterAdopt->json('data.cart.lines'));
        $this->assertNotNull($checkoutAfterAdopt->json('data.shipping_address'));
        $this->assertNull($checkoutAfterAdopt->json('data.shipping_option'));
        $this->assertSame([], $checkoutAfterAdopt->json('data.shipping_options_by_shipment'));

        // Attempting placeOrder before re-picking shipping is refused 422
        $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['bca']->id)->assertStatus(422);

        // Pick shipping again, then update cart item -> voids shipping_option
        $this->apiPickShippingOption($apiUser, 'jne:REG')
            ->assertOk()
            ->assertJsonPath('data.shipping_option.service_code', 'jne:REG');

        $lineBId = (int) collect($checkoutAfterAdopt->json('data.cart.lines'))
            ->firstWhere('purchasable_id', $productB->id)['id'];

        $this->apiUpdateCartItem($apiUser, $lineBId, 2)->assertOk();
        $this->apiLoadCheckout($apiUser)
            ->assertOk()
            ->assertJsonPath('data.shipping_option', null);

        // Pick shipping again, then remove cart item -> voids shipping_option
        $this->apiPickShippingOption($apiUser, 'jne:REG')->assertOk();
        $this->apiRemoveCartItem($apiUser, $lineBId)->assertOk();
        $this->apiLoadCheckout($apiUser)
            ->assertOk()
            ->assertJsonPath('data.shipping_option', null);

        // Pick shipping, then mutate DB CartLine behind CustomerCheckoutState -> 422 stale-plan rejection
        $this->apiPickShippingOption($apiUser, 'jne:REG')->assertOk();
        CartLine::query()->whereHas('cart', fn ($q) => $q->where('customer_id', $apiUser->id))->update(['quantity' => 3]);

        $ordersBefore = Order::query()->count();
        $staleResponse = $this->apiPlaceOrder($apiUser, $this->fixtures->paymentMethods['bca']->id)
            ->assertStatus(422);
        $this->assertStringContainsString('keranjangmu berubah', strtolower((string) $staleResponse->json('message')));
        $this->assertSame($ordersBefore, Order::query()->count());
    }
}

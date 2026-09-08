<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\GetCountriesByZone;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Shopper\Cart\CartManager;
use Shopper\Cart\Models\Cart;
use Shopper\Core\Contracts\Priceable;
use Throwable;

/**
 * Resolve the open Shopper cart for a mobile customer without PHP session.
 *
 * Guests are keyed by X-Cart-Token (stored in cart metadata), matching the
 * web storefront's session cart. Login absorbs the guest cart into the user.
 */
final class CustomerCart
{
    public function current(User $user, ?string $guestToken = null): Cart
    {
        $existing = $this->findCustomer($user);
        $guest = is_string($guestToken) && $guestToken !== ''
            ? $this->findGuest($guestToken)
            : null;

        if ($guest instanceof Cart && $existing instanceof Cart && $guest->id !== $existing->id) {
            $this->absorb($guest, $existing);

            return $existing->refresh();
        }

        if ($guest instanceof Cart && ! $existing instanceof Cart) {
            $guest->update([
                'customer_id' => $user->id,
                'metadata' => $this->withoutGuestToken($guest->metadata),
            ]);

            return $guest->refresh();
        }

        if ($existing instanceof Cart) {
            return $existing;
        }

        return $this->createCart($user->id);
    }

    public function forGuest(string $guestToken): Cart
    {
        return $this->findGuest($guestToken) ?? $this->createCart(null, $guestToken);
    }

    public function findGuest(string $guestToken): ?Cart
    {
        $cart = Cart::query()
            ->whereNull('customer_id')
            ->whereNull('completed_at')
            ->where('metadata->guest_token', $guestToken)
            ->latest('id')
            ->first();

        return $cart instanceof Cart ? $cart : null;
    }

    public function tokenFrom(Request $request): ?string
    {
        $token = $request->header('X-Cart-Token');
        if (! is_string($token)) {
            return null;
        }

        $token = trim($token);

        return Str::isUuid($token) ? $token : null;
    }

    private function findCustomer(User $user): ?Cart
    {
        $cart = Cart::query()
            ->where('customer_id', $user->id)
            ->whereNull('completed_at')
            ->latest('id')
            ->first();

        return $cart instanceof Cart ? $cart : null;
    }

    private function createCart(?int $customerId, ?string $guestToken = null): Cart
    {
        $countries = resolve(GetCountriesByZone::class)->handle();
        $zone = $countries->firstWhere('countryCode', 'ID') ?? $countries->first();
        $defaultChannel = Channel::query()->scopes('default')->first();

        return Cart::query()->create([
            'currency_code' => shopper_currency(),
            'channel_id' => $defaultChannel?->id,
            'zone_id' => $zone?->zoneId,
            'customer_id' => $customerId,
            'metadata' => $guestToken ? ['guest_token' => $guestToken] : null,
        ]);
    }

    private function absorb(Cart $source, Cart $target): void
    {
        $source->load('lines.purchasable');
        $manager = resolve(CartManager::class);

        foreach ($source->lines as $line) {
            $purchasable = $line->purchasable;
            if (! $purchasable instanceof Model || ! $purchasable instanceof Priceable) {
                continue;
            }

            try {
                $manager->add($target, $purchasable, (int) $line->quantity);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $target->refresh();
        if (is_string($source->coupon_code) && $source->coupon_code !== '' && blank($target->coupon_code)) {
            try {
                $manager->applyCoupon($target, $source->coupon_code);
            } catch (Throwable) {
                // Keep the user cart even if the guest coupon cannot apply.
            }
        }

        $source->lines()->delete();
        $source->addresses()->delete();
        $source->delete();
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function withoutGuestToken(mixed $metadata): ?array
    {
        $data = is_array($metadata) ? $metadata : [];
        unset($data['guest_token']);

        return $data === [] ? null : $data;
    }
}

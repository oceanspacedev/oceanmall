<?php

declare(strict_types=1);

namespace App\Models;

use App\Notifications\QueuedVerifyEmail;
use App\Support\CustomerCheckoutState;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Shopper\Contracts\HasStoreAuthentication;
use Shopper\Contracts\HasStoreAuthenticationRecovery;
use Shopper\Models\Contracts\ShopperUser;
use Shopper\Traits\InteractsWithShopper;
use Shopper\Traits\InteractsWithStoreAuthentication;
use Shopper\Traits\InteractsWithStoreAuthenticationRecovery;

#[Unguarded]
#[Hidden([
    'password',
    'remember_token',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'store_two_factor_secret',
    'store_two_factor_recovery_codes',
])]
final class User extends Authenticatable implements HasStoreAuthentication, HasStoreAuthenticationRecovery, MustVerifyEmail, ShopperUser
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use InteractsWithShopper;
    use InteractsWithStoreAuthentication;
    use InteractsWithStoreAuthenticationRecovery;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * A new password, suspension, or /cpanel anonymization revokes mobile tokens
     * and active sessions so access cannot outlive account revocation.
     */
    protected static function booted(): void
    {
        self::updated(static function (User $user): void {
            $anonymized = $user->wasChanged('email') && Str::endsWith(strtolower((string) $user->email), '@anonymized.local');
            $suspended = $user->wasChanged('suspended_at') && $user->suspended_at !== null;

            if ($anonymized || $suspended) {
                $user->tokens()->delete();

                if (config('session.driver') === 'database') {
                    DB::table((string) config('session.table', 'sessions'))
                        ->where('user_id', $user->getKey())
                        ->delete();
                }

                resolve(CustomerCheckoutState::class)->forget($user);

                return;
            }

            if (! $user->wasChanged('password')) {
                return;
            }

            $current = $user->currentAccessToken();
            $currentKey = $current instanceof PersonalAccessToken ? $current->getKey() : null;

            $user->tokens()
                ->when(is_int($currentKey) || is_string($currentKey), static fn ($query) => $query->whereKeyNot($currentKey))
                ->delete();
        });
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function suspend(): void
    {
        $this->forceFill(['suspended_at' => now()])->save();
    }

    public function unsuspend(): void
    {
        $this->forceFill(['suspended_at' => null])->save();
    }

    /**
     * Queued (registration never waits for the mail transport) and sent
     * once per address: the Registered event reaches this from more than one
     * listener in the same request. A resend moments later, or a changed
     * e-mail address, is sent.
     */
    public function sendEmailVerificationNotification(): void
    {
        if (! Cache::add('verify-email-sent:'.$this->getKey().':'.sha1((string) $this->email), true, now()->addSeconds(5))) {
            return;
        }

        $this->notify(new QueuedVerifyEmail);
    }

    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /** Case-insensitive lookup, so mixed-case rows from before normalisation still match. */
    public static function findByEmail(string $email): ?self
    {
        return self::query()->whereRaw('lower(email) = ?', [self::normalizeEmail($email)])->first();
    }

    public static function emailTaken(string $email, ?int $ignoreId = null): bool
    {
        return self::query()
            ->whereRaw('lower(email) = ?', [self::normalizeEmail($email)])
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    public function initials(): string
    {
        return Str::of($this->full_name ?? $this->name ?? '')
            ->explode(' ')
            ->take(2)
            ->map(fn ($word): string => Str::substr($word, 0, 1))
            ->implode('');
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: static fn (?string $value): ?string => $value === null ? null : self::normalizeEmail($value));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Shopper\Models\Contracts\ShopperUser;
use Shopper\Traits\InteractsWithShopper;

#[Unguarded]
#[Hidden([
    'password',
    'remember_token',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'store_two_factor_secret',
    'store_two_factor_recovery_codes',
])]
final class User extends Authenticatable implements MustVerifyEmail, ShopperUser
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use InteractsWithShopper;
    use Notifiable;
    use TwoFactorAuthenticatable;

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
        ];
    }
}

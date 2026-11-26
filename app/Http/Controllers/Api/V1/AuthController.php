<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\DeleteCustomerAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CustomerCart;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Spatie\Permission\Models\Role;
use Throwable;

final class AuthController extends Controller
{
    public function __construct(
        private readonly CustomerCart $customerCart,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $request->merge(['email' => User::normalizeEmail((string) $request->input('email'))]);
        $emailRule = app()->runningUnitTests() ? 'email:rfc' : 'email:rfc,dns';
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', $emailRule, 'max:255', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && User::emailTaken($value)) {
                    $fail('Email sudah terdaftar.');
                }
            }],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        $user = User::query()->create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone_number' => $data['phone_number'] ?? null,
            'password' => $data['password'],
        ]);

        $role = config('shopper.admin.roles.user');
        if (is_string($role) && $role !== '' && Role::query()->where('name', $role)->exists()) {
            $user->assignRole($role);
        }

        $this->absorbGuestCart($request, $user);

        // Queues the verification mail (same as web registration), after the
        // account is complete: a mail problem never leaves a half-made
        // account or fails the registration. The app does not gate on it; web
        // checkout and account pages do (`verified`).
        try {
            event(new Registered($user));
        } catch (Throwable $e) {
            report($e);
        }

        return $this->tokenResponse($user, 201);
    }

    /** Wrong two-step codes allowed per account before a pause. */
    private const TWO_FACTOR_ATTEMPTS = 5;

    private const TWO_FACTOR_DECAY_SECONDS = 600;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            // Two-step verification: an authenticator code or a recovery code.
            'code' => ['nullable', 'string', 'max:64'],
        ]);

        $user = User::findByEmail($data['email']);

        if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
            return response()->json([
                'message' => 'Email atau kata sandi salah.',
            ], 422);
        }

        if ($user->isSuspended()) {
            return response()->json([
                'message' => 'Akun ini telah dinonaktifkan sementara.',
            ], 403);
        }

        // An account protected by two-step verification on the website is
        // protected here too: no token without a valid code. Only asked
        // after the password is right, so it does not reveal which accounts
        // use it.
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $code = trim((string) ($data['code'] ?? ''));

            if ($code === '') {
                return response()->json([
                    // An app build from before the code field never sends
                    // the key: tell its user what to do.
                    'message' => $request->has('code')
                        ? 'Masukkan kode verifikasi dua langkah dari aplikasi autentikator.'
                        : 'Akun ini memakai verifikasi dua langkah. Perbarui aplikasi OceanMall ke versi terbaru untuk masuk.',
                    'two_factor_required' => true,
                ], 422);
            }

            // Per account, whatever the IP: the caller already has the
            // password, so the six digits must not be guessable by volume
            // (the route's own throttle is per IP). Same budget as the web.
            $attempts = 'api-two-factor:'.$user->getKey();

            if (RateLimiter::tooManyAttempts($attempts, self::TWO_FACTOR_ATTEMPTS)) {
                $seconds = RateLimiter::availableIn($attempts);

                return response()->json([
                    'message' => 'Terlalu banyak percobaan kode. Coba lagi dalam '.max(1, (int) ceil($seconds / 60)).' menit.',
                    'two_factor_required' => true,
                ], 429)->header('Retry-After', (string) $seconds);
            }

            if (! $this->validTwoFactorCode($user, $code)) {
                RateLimiter::hit($attempts, self::TWO_FACTOR_DECAY_SECONDS);

                return response()->json([
                    'message' => 'Kode verifikasi dua langkah salah.',
                    'two_factor_required' => true,
                ], 422);
            }

            RateLimiter::clear($attempts);
        }

        // One device per account: a new login signs out the previous one. Store reviewers (Apple
        // App Review, Play's pre-launch crawler) share one demo account across several devices at
        // once, so the addresses listed in REVIEWER_DEMO_EMAILS keep their other sessions.
        if (! $this->isReviewerDemoAccount($user)) {
            $user->tokens()->where('name', 'mobile')->delete();
        }

        $this->absorbGuestCart($request, $user);

        return $this->tokenResponse($user);
    }

    /**
     * A current authenticator code, or an unused recovery code (which is
     * consumed), exactly as Fortify's web two-factor challenge accepts them.
     */
    private function validTwoFactorCode(User $user, string $code): bool
    {
        try {
            // Authenticator apps show "123 456": spaces are not part of it.
            $totp = (string) preg_replace('/\s+/', '', $code);

            if (preg_match('/^\d{6}$/', $totp) === 1) {
                $secret = Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret);

                if (resolve(TwoFactorAuthenticationProvider::class)->verify($secret, $totp)) {
                    return true;
                }
            }

            $recovery = collect($user->recoveryCodes())
                ->first(static fn (mixed $candidate): bool => is_string($candidate) && hash_equals($candidate, $code));

            if (is_string($recovery)) {
                $user->replaceRecoveryCode($recovery);

                return true;
            }
        } catch (Throwable $e) {
            report($e);
        }

        return false;
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Keluar.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->userPayload($user),
        ]);
    }

    public function resendVerificationEmail(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email sudah terverifikasi.']);
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Gagal mengirim email verifikasi. Coba lagi nanti.'], 500);
        }

        return response()->json(['message' => 'Tautan verifikasi email telah dikirim ulang.']);
    }

    /**
     * In-app account deletion (App Store 5.1.1(v), Play account-deletion policy).
     *
     * The password is checked against the `sanctum` guard: the web `current_password` rule
     * defaults to the session guard and would reject every Bearer-token request.
     */
    public function destroy(Request $request, DeleteCustomerAccount $deleteAccount): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password:sanctum'],
        ]);

        $deleteAccount->handle($request->user());

        return response()->json(['message' => 'Akunmu sudah dihapus.']);
    }

    private function isReviewerDemoAccount(User $user): bool
    {
        $emails = array_map(
            static fn (string $email): string => User::normalizeEmail($email),
            (array) config('auth.reviewer_demo_emails', []),
        );

        return in_array(User::normalizeEmail((string) $user->email), $emails, true);
    }

    private function tokenResponse(User $user, int $status = 200): JsonResponse
    {
        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'data' => $this->userPayload($user),
        ], $status);
    }

    private function absorbGuestCart(Request $request, User $user): void
    {
        $token = $this->customerCart->tokenFrom($request);
        if ($token === null) {
            return;
        }

        try {
            $this->customerCart->current($user, $token);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'email_verified_at' => optional($user->email_verified_at)?->toIso8601String(),
        ];
    }
}

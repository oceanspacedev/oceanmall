<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Account\DeleteCustomerAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CustomerCart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
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
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', static function (string $attribute, mixed $value, \Closure $fail): void {
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
            'email_verified_at' => now(),
        ]);

        $role = config('shopper.admin.roles.user');
        if (is_string($role) && $role !== '' && Role::query()->where('name', $role)->exists()) {
            $user->assignRole($role);
        }

        $this->absorbGuestCart($request, $user);

        return $this->tokenResponse($user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::findByEmail($data['email']);

        if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
            return response()->json([
                'message' => 'Email atau kata sandi salah.',
            ], 422);
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
        ];
    }
}

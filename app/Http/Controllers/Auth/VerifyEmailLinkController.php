<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies signed email confirmation links for both signed-in web sessions
 * (preserving Fortify's own guardrails) and guest clicks from mobile email apps.
 */
final class VerifyEmailLinkController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): Response|RedirectResponse|Responsable
    {
        $authenticated = $request->user();

        if ($authenticated !== null) {
            abort_unless(hash_equals((string) $authenticated->getKey(), (string) $id), 403);
            abort_unless(hash_equals(sha1($authenticated->getEmailForVerification()), (string) $hash), 403);

            if (! $authenticated->hasVerifiedEmail() && $authenticated->markEmailAsVerified()) {
                event(new Verified($authenticated));
            }

            return app(VerifyEmailResponse::class)->toResponse($request);
        }

        $user = User::query()->find($id);

        abort_unless(
            $user !== null && hash_equals(sha1($user->getEmailForVerification()), (string) $hash),
            403,
        );

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()
            ->route('login', ['verified' => 1])
            ->with('status', 'Email berhasil diverifikasi. Silakan kembali ke aplikasi atau masuk ke akunmu.');
    }
}

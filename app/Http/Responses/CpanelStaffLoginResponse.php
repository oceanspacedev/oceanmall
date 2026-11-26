<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Http\Middleware\EnsureCpanelIsStaffOnly;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Shopper\Contracts\LoginResponse as LoginResponseContract;
use Shopper\Facades\Shopper;

final class CpanelStaffLoginResponse implements LoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): mixed
    {
        $user = Shopper::auth()->user();

        if ($user instanceof \App\Models\User && $user->isSuspended()) {
            Shopper::auth()->logout();

            throw ValidationException::withMessages([
                'data.email' => __('Your staff account has been suspended.'),
            ]);
        }

        if (! EnsureCpanelIsStaffOnly::userIsStaff($user)) {
            Shopper::auth()->logout();

            throw ValidationException::withMessages([
                'data.email' => __('This account cannot access the store operations panel.'),
            ]);
        }

        return redirect()->intended(route('shopper.dashboard'));
    }
}

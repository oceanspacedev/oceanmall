<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Illuminate\Validation\Rule;
use Shopper\Core\Enum\GenderType;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'first_name' => $this->nameRules(),
            'last_name' => $this->nameRules(),
            'gender' => ['nullable', Rule::enum(GenderType::class)],
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        // The DNS check confirms the email domain really exists, but it needs
        // network access and rejects reserved domains such as example.com, so
        // it is skipped while running the test suite.
        $emailRule = app()->runningUnitTests() ? 'email:rfc' : 'email:rfc,dns';

        return [
            'required',
            'string',
            $emailRule,
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}

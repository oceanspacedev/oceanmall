<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\SlideOvers;

use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class CreateTeamMember extends \Shopper\Livewire\SlideOvers\CreateTeamMember
{
    public function store(): void
    {
        $this->authorize('access_setting');

        $data = $this->form->getState();
        $data['email'] = User::normalizeEmail((string) ($data['email'] ?? ''));

        $this->validate([
            'data.email' => ['required', 'email', 'max:255'],
            'data.password' => ['required', 'string', Password::default()],
        ]);

        if (User::emailTaken($data['email'])) {
            Notification::make()
                ->title('Email sudah terdaftar.')
                ->danger()
                ->send();

            return;
        }

        /** @var Role $role */
        $role = Role::findById((int) $data['role_id']);
        $adminRoleName = (string) config('shopper.admin.roles.admin');
        $userRoleName = (string) config('shopper.admin.roles.user');
        $actor = shopper()->auth()->user();

        if ($role->name === $userRoleName || ($role->name === $adminRoleName && ! $actor?->isAdmin())) {
            Notification::make()
                ->title('Hanya administrator utama yang dapat menetapkan peran ini.')
                ->danger()
                ->send();

            return;
        }

        $userModel = config('auth.providers.users.model');
        $user = $userModel::query()->create([
            'email' => $data['email'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'password' => Hash::make((string) $data['password']),
            'phone_number' => $data['phone_number'] ?? null,
            'gender' => $data['gender'] ?? null,
            'email_verified_at' => now()->toDateTimeString(),
        ]);

        $user->assignRole([$role->name]);

        $this->dispatch('teamUpdate');

        if (! empty($data['send_mail'])) {
            $user->sendEmailVerificationNotification();
        }

        Notification::make()
            ->title(__('shopper::notifications.users_roles.admin_added'))
            ->success()
            ->send();

        $this->closePanel();
    }

    public function form(Schema $schema): Schema
    {
        return parent::form($schema);
    }
}

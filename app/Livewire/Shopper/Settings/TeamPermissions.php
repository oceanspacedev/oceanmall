<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Settings;

use Filament\Notifications\Notification;
use Shopper\Core\Models\Permission;

class TeamPermissions extends \Shopper\Livewire\Components\Settings\Team\Permissions
{
    public function togglePermission(int $id): void
    {
        $this->authorize('access_setting');

        if ($this->isProtectedAdminRole()) {
            Notification::make()
                ->title('Hanya administrator yang dapat mengubah izin peran administrator.')
                ->danger()
                ->send();

            return;
        }

        parent::togglePermission($id);
    }

    public function removePermission(int $id): void
    {
        $this->authorize('access_setting');

        $permission = Permission::query()->find($id);
        if ($permission !== null && ! $permission->can_be_removed) {
            Notification::make()
                ->title('Permission sistem bawaan tidak dapat dihapus.')
                ->danger()
                ->send();

            return;
        }

        if ($this->isProtectedAdminRole()) {
            Notification::make()
                ->title('Hanya administrator yang dapat menghapus izin ini.')
                ->danger()
                ->send();

            return;
        }

        parent::removePermission($id);
    }

    private function isProtectedAdminRole(): bool
    {
        $adminRole = (string) config('shopper.admin.roles.admin', 'administrator');

        return $this->role->name === $adminRole && ! shopper()->auth()->user()?->isAdmin();
    }
}

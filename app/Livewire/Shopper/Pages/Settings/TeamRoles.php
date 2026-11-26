<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages\Settings;

use Filament\Actions\Action;
use Filament\Notifications\Notification;

class TeamRoles extends \Shopper\Livewire\Pages\Settings\Team\RolePermission
{
    public function deleteAction(): Action
    {
        $protected = in_array($this->role->name, [
            (string) config('shopper.admin.roles.admin', 'administrator'),
            (string) config('shopper.admin.roles.user', 'user'),
        ], true);

        return parent::deleteAction()
            ->visible(! $protected && (bool) $this->role->can_be_removed);
    }

    public function save(): void
    {
        $this->authorize('access_setting');

        if ($this->role->name === (string) config('shopper.admin.roles.admin', 'administrator')
            && ! shopper()->auth()->user()?->isAdmin()) {
            Notification::make()
                ->title('Hanya administrator yang dapat mengubah detail peran ini.')
                ->danger()
                ->send();

            return;
        }

        parent::save();
    }
}

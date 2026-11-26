<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Account;

use Filament\Notifications\Notification;
use Shopper\Actions\Auth\DisableTwoFactorAuthentication;
use Shopper\Actions\Auth\EnableTwoFactorAuthentication;
use Shopper\Actions\Auth\GenerateNewRecoveryCodes;

class TwoFactor extends \Shopper\Livewire\Components\Account\TwoFactor
{
    public function enableTwoFactorAuthentication(EnableTwoFactorAuthentication $enable): void
    {
        parent::enableTwoFactorAuthentication($enable);

        Notification::make()
            ->title('Autentikasi dua faktor (2FA) telah diaktifkan. Simpan kode pemulihan dengan aman.')
            ->success()
            ->send();
    }

    public function disableTwoFactorAuthentication(DisableTwoFactorAuthentication $disable): void
    {
        parent::disableTwoFactorAuthentication($disable);

        Notification::make()
            ->title('Autentikasi dua faktor (2FA) telah dinonaktifkan.')
            ->warning()
            ->send();
    }

    public function regenerateRecoveryCodes(GenerateNewRecoveryCodes $generate): void
    {
        parent::regenerateRecoveryCodes($generate);

        Notification::make()
            ->title('Kode pemulihan 2FA baru telah dibuat.')
            ->success()
            ->send();
    }
}

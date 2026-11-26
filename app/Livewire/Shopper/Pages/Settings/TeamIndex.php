<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages\Settings;

use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Shopper\Core\Models\Contracts\ShopperUser;
use Mckenziearts\Icons\Untitledui\Enums\Untitledui;

class TeamIndex extends \Shopper\Livewire\Pages\Settings\Team\Index
{
    public function table(Table $table): Table
    {
        $userModel = config('auth.providers.users.model');
        $customerRole = (string) config('shopper.admin.roles.user', 'user');
        $adminRole = (string) config('shopper.admin.roles.admin', 'administrator');

        return $table
            ->query(
                $userModel::query()
                    ->with('roles')
                    ->whereHas('roles', static fn ($query) => $query->where('name', '<>', $customerRole)),
            )
            ->columns([
                ViewColumn::make('full_name')
                    ->label(__('shopper::forms.label.full_name'))
                    ->view('shopper::livewire.tables.cells.administrators.name'),
                TextColumn::make('email')
                    ->label(__('shopper::forms.label.email'))
                    ->icon(static fn (ShopperUser $record): BackedEnum => $record->email_verified_at
                        ? Untitledui::CheckVerified02
                        : Untitledui::AlertCircle)
                    ->iconColor(static fn (ShopperUser $record): string => $record->email_verified_at ? 'success' : 'danger'),
                TextColumn::make('roles_label')
                    ->label(__('shopper::forms.label.role')),
                TextColumn::make('two_factor_state')
                    ->label('2FA')
                    ->badge()
                    ->state(static function (ShopperUser $record): string {
                        return (filled($record->store_two_factor_secret ?? null) || filled($record->two_factor_confirmed_at ?? null))
                            ? 'Aktif'
                            : 'Nonaktif';
                    })
                    ->color(static fn (string $state): string => $state === 'Aktif' ? 'success' : 'gray'),
                TextColumn::make('suspended_state')
                    ->label('Status')
                    ->badge()
                    ->state(static fn (ShopperUser $record): string => $record instanceof User && $record->isSuspended() ? 'Disuspend' : 'Aktif')
                    ->color(static fn (string $state): string => $state === 'Disuspend' ? 'danger' : 'success'),
            ])
            ->recordActions([
                Action::make('toggle_suspend')
                    ->label(static fn (ShopperUser $record): string => $record instanceof User && $record->isSuspended() ? 'Aktifkan' : 'Suspend')
                    ->color(static fn (ShopperUser $record): string => $record instanceof User && $record->isSuspended() ? 'success' : 'warning')
                    ->authorize('access_setting')
                    ->visible(static fn (ShopperUser $record): bool => (bool) shopper()->auth()->user()?->isAdmin()
                        && (int) $record->getKey() !== (int) shopper()->auth()->id())
                    ->requiresConfirmation()
                    ->action(static function (ShopperUser $record) use ($adminRole): void {
                        if (! $record instanceof User) {
                            return;
                        }

                        if ($record->isSuspended()) {
                            $record->unsuspend();
                            Notification::make()->title('Akun staf diaktifkan kembali.')->success()->send();

                            return;
                        }

                        if ($record->isAdmin()) {
                            $otherActiveAdmins = User::query()
                                ->whereNull('suspended_at')
                                ->whereKeyNot($record->getKey())
                                ->whereHas('roles', static fn ($q) => $q->where('name', $adminRole))
                                ->exists();

                            if (! $otherActiveAdmins) {
                                Notification::make()
                                    ->title('Minimal satu administrator aktif harus tetap tersedia.')
                                    ->danger()
                                    ->send();

                                return;
                            }
                        }

                        $record->suspend();
                        Notification::make()->title('Akun staf disuspend dan sesi dicabut.')->warning()->send();
                    }),
                DeleteAction::make('delete')
                    ->icon(Untitledui::Trash03)
                    ->iconButton()
                    ->label(__('shopper::forms.actions.delete'))
                    ->authorize('access_setting')
                    ->visible(static fn (ShopperUser $record): bool => (bool) shopper()->auth()->user()?->isAdmin() && ! $record->isAdmin())
                    ->successNotificationTitle(__('shopper::notifications.users_roles.admin_deleted')),
            ]);
    }
}

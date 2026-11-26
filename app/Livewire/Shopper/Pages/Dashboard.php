<?php

declare(strict_types=1);

namespace App\Livewire\Shopper\Pages;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('shopper::components.layouts.app')]
class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.shopper.pages.dashboard', [
            'canViewFinance' => Gate::allows('finance-dashboard'),
        ])->title(__('shopper::pages/dashboard.menu'));
    }
}

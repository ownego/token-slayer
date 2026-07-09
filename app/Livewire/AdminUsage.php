<?php

namespace App\Livewire;

use App\Services\DamageTotals;
use Livewire\Component;

class AdminUsage extends Component
{
    public function render()
    {
        return view('livewire.admin-usage', [
            'accounts' => app(DamageTotals::class)->perAccount(),
            'users' => app(DamageTotals::class)->perUser(),
        ]);
    }
}

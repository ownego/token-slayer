<?php

namespace App\Livewire;

use App\Models\Boss;
use App\Models\User;
use Livewire\Component;

class Battlefield extends Component
{
    public ?Boss $boss = null;

    public $fighters = [];

    public function mount(): void
    {
        $this->boss = Boss::where('status', 'alive')->orderByDesc('number')->first();
        $this->fighters = User::where('last_event_at', '>=', now()->subMinutes(config('game.idle_minutes')))
            ->get();
    }

    public function render()
    {
        return view('livewire.battlefield');
    }
}

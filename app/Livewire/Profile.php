<?php

namespace App\Livewire;

use Illuminate\Support\Str;
use Livewire\Component;

class Profile extends Component
{
    public ?string $plainToken = null;

    public function mount(): void
    {
        $this->plainToken = session()->pull('hook_token_plain');
    }

    public function regenerate(): void
    {
        $plain = Str::random(48);
        auth()->user()->forceFill(['hook_token' => hash('sha256', $plain)])->save();
        $this->plainToken = $plain;
    }

    public function render()
    {
        return view('livewire.profile', [
            'user' => auth()->user(),
            'claudeSnippet' => view('partials.claude-snippet', [
                'token' => $this->plainToken ?? 'YOUR_TOKEN',
                'baseUrl' => url('/api/events'),
            ])->render(),
            'codexSnippet' => view('partials.codex-snippet', [
                'token' => $this->plainToken ?? 'YOUR_TOKEN',
                'baseUrl' => url('/api/events').'?provider=codex',
            ])->render(),
        ]);
    }
}

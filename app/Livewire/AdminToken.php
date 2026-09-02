<?php

namespace App\Livewire;

use App\Models\IdeAccessToken;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Lets an admin mint their own `admin_bearer` token, for
 * `token-slayer admin login --token <token>` on their machine — the same
 * "generate a token, show it once" pattern {@see Setup} already uses for
 * the employee-facing hook token.
 */
class AdminToken extends Component
{
    /**
     * @var string|null the freshly minted token, shown once
     */
    public ?string $plainToken = null;

    /**
     * Mints a fresh admin bearer token for the current user.
     *
     * @return void
     */
    public function generateToken(): void
    {
        [$this->plainToken] = IdeAccessToken::issueAdminBearer(auth()->user());
    }

    /**
     * @return View
     */
    public function render(): View
    {
        return view('livewire.admin-token');
    }
}

<?php

namespace App\Filament\Auth;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Auth\Http\Responses\LogoutResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Where an admin lands after logging out of the panel. Filament's own
 * default ({@see LogoutResponse}) falls back to the panel's base URL when
 * no login page is registered, which for us immediately bounces back into
 * the Slack OAuth redirect — jarring right after logging out. Send them to
 * the public battlefield instead.
 */
class BattlefieldLogoutResponse implements LogoutResponseContract
{
    /**
     * Redirect a just-logged-out admin to the public battlefield.
     *
     * @param  Request  $request  the logout request
     * @return RedirectResponse|Redirector
     */
    public function toResponse($request): RedirectResponse|Redirector
    {
        return redirect()->route('battlefield');
    }
}

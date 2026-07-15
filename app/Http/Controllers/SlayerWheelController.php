<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\RedirectResponse;

class SlayerWheelController extends Controller
{
    /**
     * Redirect the install script's wheel download to slayer-cli's latest
     * GitHub Release asset. slayer-cli is built and released from its own
     * repo now — this server holds no copy of the wheel to stream. 404s
     * cleanly when no release URL is configured yet, so the install
     * script's tolerant `|| echo "...skipped"` fallback degrades gracefully.
     *
     * @return RedirectResponse
     */
    public function __invoke(): RedirectResponse
    {
        $url = config('token_slayer.slayer_cli_wheel_url');

        if (! $url) {
            abort(404);
        }

        return redirect()->away($url);
    }
}

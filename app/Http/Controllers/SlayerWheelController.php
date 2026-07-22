<?php

namespace App\Http\Controllers;

use App\Services\SlayerWheelProvider;
use Illuminate\Http\Response;

class SlayerWheelController extends Controller
{
    /**
     * Build the controller.
     *
     * @param  SlayerWheelProvider  $wheel  supplies the current wheel's bytes
     * @return void
     */
    public function __construct(private readonly SlayerWheelProvider $wheel) {}

    /**
     * Serve the current slayer-cli wheel, behind the hook.token middleware.
     * The client only ever talks to this server; the PAT and the GitHub URL
     * stay server-side. Any failure aborts with a generic 503 whose message
     * mentions neither GitHub nor credentials, so a failure of the server's
     * OWN auth is never disclosed to the caller.
     *
     * @return Response
     */
    public function __invoke(): Response
    {
        $bytes = $this->wheel->bytes();

        if ($bytes === null) {
            abort(503, 'slayer-cli is temporarily unavailable. Try again shortly.');
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="slayer_cli-latest.whl"',
        ]);
    }
}

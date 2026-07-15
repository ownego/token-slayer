<?php

namespace App\Http\Controllers;

use App\Services\SlayerCliWheelFetcher;
use Illuminate\Http\Response;

class SlayerWheelController extends Controller
{
    /**
     * Build the controller with the fetcher it delegates the authenticated
     * GitHub release download to.
     *
     * @param  SlayerCliWheelFetcher  $fetcher  fetches the wheel bytes from the private release
     * @return void
     */
    public function __construct(private readonly SlayerCliWheelFetcher $fetcher) {}

    /**
     * Stream the install script's wheel download. slayer-cli lives in its
     * own PRIVATE repo now — this server never builds or stores the wheel
     * itself, it fetches the bytes server-side (repo-scoped token) and hands
     * them back, so an anonymous install script never needs GitHub
     * credentials of its own. 404s cleanly when unavailable, so the install
     * script's tolerant `|| echo "...skipped"` fallback degrades gracefully.
     *
     * @return Response
     */
    public function __invoke(): Response
    {
        $bytes = $this->fetcher->fetch();

        if ($bytes === null) {
            abort(404);
        }

        return response($bytes, 200, ['Content-Type' => 'application/octet-stream']);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class SlayerWheelController extends Controller
{
    /**
     * Storage-relative path (under `storage/app/`) to the built slayer-cli
     * wheel. Populated out of band by the `slayer-cli` build script — this
     * controller only streams whatever is currently on disk.
     *
     * @var string
     */
    private const string WHEEL_STORAGE_PATH = 'app/dist/slayer_cli-latest.whl';

    /**
     * Stream the built slayer-cli wheel so the install script can
     * `pip install` it directly from this host. 404s cleanly when no wheel
     * has been built/uploaded yet, so the install script's tolerant
     * `|| echo "...skipped"` fallback degrades gracefully.
     *
     * @return Response
     */
    public function __invoke(): Response
    {
        $path = storage_path(self::WHEEL_STORAGE_PATH);

        if (! File::exists($path)) {
            abort(404);
        }

        return response(File::get($path), 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="slayer_cli-latest.whl"',
        ]);
    }
}

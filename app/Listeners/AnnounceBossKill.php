<?php

namespace App\Listeners;

use App\Events\BossKilled;
use Illuminate\Support\Facades\Http;

class AnnounceBossKill
{
    /**
     * Handle the event.
     */
    public function handle(BossKilled $event): void
    {
        $url = config('game.slack_kill_webhook_url');

        if (! $url) {
            return;
        }

        Http::post($url, [
            'text' => sprintf(
                '🐉 Boss #%d defeated! Killing blow: @%s',
                $event->boss->number,
                $event->killer->slack_handle,
            ),
        ]);
    }
}

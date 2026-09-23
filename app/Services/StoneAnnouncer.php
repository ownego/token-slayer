<?php

namespace App\Services;

use App\Enums\BossCharacter;
use App\Models\Boss;
use App\Support\CacheKeys;
use App\Support\StoneClock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts a Slack line each time a boss with a stone clock collects a stone.
 * Runs from the boss:announce-stone schedule at the stone-clock instant;
 * idempotent per (boss, stone) so a retry inside the same day stays quiet.
 */
class StoneAnnouncer
{
    /**
     * Announce the boss's latest stone if there is one and it hasn't been
     * announced yet.
     *
     * @param  Boss|null  $boss  The alive boss, or null when none is up.
     * @return bool Whether a message was posted.
     */
    public function announce(?Boss $boss): bool
    {
        if ($boss === null) {
            return false;
        }

        $state = BossCharacter::forNumber($boss->number)->scriptState($boss);
        $stones = (int) ($state['stones'] ?? 0);

        if ($stones < 1) {
            return false;
        }

        $url = config('services.slack_notifier.webhook_url');

        if (! $url) {
            Log::debug('Slack stone announcement skipped: webhook URL not configured', ['boss_id' => $boss->id]);

            return false;
        }

        // One post per (boss, stone), kept for the boss's whole life: the count
        // stops moving once the gauntlet is complete, so an expiring guard
        // would re-announce the last stone. At most `max` keys per boss.
        // Invalidated only by the failure path below.
        $guard = CacheKeys::bossStoneAnnounced($boss->id, $stones);

        if (! Cache::add($guard, true)) {
            return false;
        }

        try {
            Http::post($url, $this->payload($boss, $stones));

            return true;
        } catch (Throwable $e) {
            // Release the guard so the next scheduler run can retry the post.
            Cache::forget($guard);
            Log::warning('Slack stone announcement failed', [
                'boss_id' => $boss->id,
                'stones' => $stones,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Slack Block Kit payload for the Nth stone.
     *
     * @param  Boss  $boss
     * @param  int  $stones
     * @return array{text: string, blocks: array<int, array<string, mixed>>}
     */
    private function payload(Boss $boss, int $stones): array
    {
        $max = (int) config('game.stones.max');
        $name = $boss->name ?: sprintf('Boss #%d', $boss->number);
        $count = "{$stones}/{$max}";

        if ($stones >= $max) {
            $headline = sprintf('💎 %s collected the %s — the gauntlet is complete (%s)', $name, StoneClock::nameOf($stones), $count);
            $context = sprintf('%s holds every Infinity Stone.', $name);
        } else {
            $headline = sprintf('💎 %s collected the %s (%s)', $name, StoneClock::nameOf($stones), $count);
            $context = sprintf(
                '%d more until the gauntlet is complete — the next one lands at %02d:%02d tomorrow.',
                $max - $stones,
                config('game.stones.hour'),
                config('game.stones.minute'),
            );
        }

        return [
            'text' => $headline,
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => ['type' => 'plain_text', 'text' => $headline, 'emoji' => true],
                ],
                [
                    'type' => 'context',
                    'elements' => [['type' => 'mrkdwn', 'text' => $context]],
                ],
            ],
        ];
    }
}

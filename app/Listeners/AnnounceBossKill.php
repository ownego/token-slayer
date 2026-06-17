<?php

namespace App\Listeners;

use App\Events\BossKilled;
use App\Models\Boss;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnnounceBossKill implements ShouldQueue
{
    private const MEDALS = ['🥇', '🥈', '🥉'];

    public function handle(BossKilled $event): void
    {
        $url = config('services.slack_notifier.webhook_url');

        if (! $url) {
            return;
        }

        $killed = $event->boss;
        $killer = $event->killer;
        $newBoss = Boss::where('status', 'alive')->orderByDesc('number')->first();
        $topDealers = $this->topDamageDealers($killed);

        $payload = [
            'text' => $this->fallbackText($killed, $killer, $newBoss),
            'blocks' => $this->buildBlocks($killed, $killer, $newBoss, $topDealers),
        ];

        try {
            Http::post($url, $payload);
        } catch (Throwable $e) {
            Log::warning('Slack boss-kill notification failed', [
                'boss_id' => $killed->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return Collection<int, object{user: ?User, damage: int}>
     */
    private function topDamageDealers(Boss $killed): Collection
    {
        return Event::query()
            ->where('boss_id', $killed->id)
            ->select('user_id', DB::raw('SUM(tokens) as damage'))
            ->groupBy('user_id')
            ->orderByDesc('damage')
            ->limit(3)
            ->with('user:id,name,slack_handle,display_name')
            ->get()
            ->map(fn (Event $row) => (object) [
                'user' => $row->user,
                'damage' => (int) $row->damage,
            ]);
    }

    private function fallbackText(Boss $killed, User $killer, ?Boss $newBoss): string
    {
        $killerLabel = $this->mention($killer);
        $line = sprintf('🐉 %s defeated by %s', $this->bossLabel($killed), $killerLabel);

        if ($newBoss) {
            $line .= sprintf(' · ⚔️ %s (%s HP) incoming', $this->bossLabel($newBoss), number_format($newBoss->max_hp));
        }

        return $line;
    }

    private function bossLabel(Boss $boss): string
    {
        return $boss->name ?: sprintf('Boss #%d', $boss->number);
    }

    /**
     * @param  Collection<int, object{user: ?User, damage: int}>  $topDealers
     * @return array<int, array<string, mixed>>
     */
    private function buildBlocks(Boss $killed, User $killer, ?Boss $newBoss, Collection $topDealers): array
    {
        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => sprintf('🐉 %s defeated!', $this->bossLabel($killed)),
                    'emoji' => true,
                ],
            ],
            [
                'type' => 'section',
                'fields' => $this->summaryFields($killer, $newBoss),
            ],
        ];

        if ($topDealers->isNotEmpty()) {
            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Top damage*\n".$this->renderLeaderboard($topDealers),
                ],
            ];
        }

        $context = $this->contextLine($killed);

        if ($context !== null) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [
                    ['type' => 'mrkdwn', 'text' => $context],
                ],
            ];
        }

        return $blocks;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function summaryFields(User $killer, ?Boss $newBoss): array
    {
        $fields = [
            [
                'type' => 'mrkdwn',
                'text' => "*Killing blow*\n".$this->mention($killer),
            ],
        ];

        if ($newBoss) {
            $fields[] = [
                'type' => 'mrkdwn',
                'text' => sprintf("*New boss*\n⚔️ %s (%s HP)", $this->bossLabel($newBoss), number_format($newBoss->max_hp)),
            ];
        }

        return $fields;
    }

    /**
     * @param  Collection<int, object{user: ?User, damage: int}>  $topDealers
     */
    private function renderLeaderboard(Collection $topDealers): string
    {
        return $topDealers
            ->values()
            ->map(fn (object $row, int $i) => sprintf(
                '%s %s — %s',
                self::MEDALS[$i] ?? '•',
                $row->user ? $this->mention($row->user) : 'unknown',
                number_format($row->damage),
            ))
            ->implode("\n");
    }

    private function mention(User $user): string
    {
        if ($user->slack_handle) {
            return '@'.$user->slack_handle;
        }

        return $user->name ?? 'unknown';
    }

    private function contextLine(Boss $killed): ?string
    {
        $parts = [];

        if ($killed->spawned_at) {
            $parts[] = 'spawned '.$killed->spawned_at->diffForHumans();
        }

        if ($killed->spawned_at && $killed->defeated_at) {
            $parts[] = 'killed in '.$killed->spawned_at->diffForHumans($killed->defeated_at, ['parts' => 2, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]);
        }

        return $parts ? implode(' · ', $parts) : null;
    }
}

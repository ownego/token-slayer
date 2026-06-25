<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Slack\SlackProfileFetcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('slack:backfill-display-names')]
#[Description('Fetch each linked user\'s Slack display name via the bot token and store it')]
class BackfillSlackDisplayNames extends Command
{
    public function handle(SlackProfileFetcher $profiles): int
    {
        if (blank(config('services.slack.bot_token'))) {
            $this->error('SLACK_BOT_TOKEN is not configured; set it before running this command.');

            return self::FAILURE;
        }

        $updated = 0;

        User::query()
            ->whereNotNull('slack_user_id')
            ->each(function (User $user) use ($profiles, &$updated): void {
                $displayName = $profiles->displayNameFor($user->slack_user_id);

                if ($displayName === null || $displayName === $user->display_name) {
                    return;
                }

                $user->update(['display_name' => $displayName]);
                $updated++;

                $this->line("Updated {$user->slack_user_id} → {$displayName}");
            });

        $this->info("Done. Updated {$updated} user(s).");

        return self::SUCCESS;
    }
}

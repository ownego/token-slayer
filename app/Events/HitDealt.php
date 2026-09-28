<?php

namespace App\Events;

use App\Models\Boss;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HitDealt implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  User  $user  the fighter who dealt the hit
     * @param  int  $damage  tokens dealt this turn
     * @param  Boss  $boss  the boss after the hit landed
     * @param  ?string  $model  raw model id that produced the turn, when known
     * @param  ?string  $flair  flair key the model earns, or null for none
     * @param  ?int  $flairDurationMs  how long the flair badge stays up, or
     *                                 null when there is no flair
     * @param  ?string  $flairColor  admin-configured hex color for the flair
     *                               effect, or null when there is no flair
     * @param  int  $inputTokens  input tokens the turn that produced this
     *                            hit carried, 0 when unknown
     * @param  int  $cacheCreationInputTokens  cache-creation input tokens the
     *                                         turn carried, 0 when unknown
     * @param  int  $cacheReadInputTokens  cache-read input tokens the turn
     *                                     carried, 0 when unknown
     */
    public function __construct(
        public User $user,
        public int $damage,
        public Boss $boss,
        public ?string $model = null,
        public ?string $flair = null,
        public ?int $flairDurationMs = null,
        public ?string $flairColor = null,
        public int $inputTokens = 0,
        public int $cacheCreationInputTokens = 0,
        public int $cacheReadInputTokens = 0,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('battlefield')];
    }

    public function broadcastAs(): string
    {
        return 'HitDealt';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->user->id,
            'slack_handle' => $this->user->displayHandle(),
            'avatar_url' => $this->user->avatarProxyUrl(),
            'damage' => $this->damage,
            'boss_id' => $this->boss->id,
            'boss_hp_after' => $this->boss->current_hp,
            'boss_max_hp' => $this->boss->max_hp,
            'model' => $this->model,
            'flair' => $this->flair,
            'flair_duration_ms' => $this->flairDurationMs,
            'flair_color' => $this->flairColor,
            'input_tokens' => $this->inputTokens,
            'cache_creation_input_tokens' => $this->cacheCreationInputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
        ];
    }
}

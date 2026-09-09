<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * An org account's provider — `accounts.provider`. Two values by
 * construction (the envelope/credential split only supports these two
 * credential shapes: `claude_credentials`, `codex_credentials`). Distinct
 * from `events.provider` (the hook payload's wire value, e.g.
 * `claude-code`/`claude-ai`/`cowork`/`codex`) — that field stays a raw
 * string throughout the codebase and is never cast to this enum.
 */
enum Provider: string implements HasColor, HasLabel
{
    /**
     * A Claude (Anthropic) org account.
     */
    case Claude = 'claude';

    /**
     * A Codex (OpenAI/ChatGPT) org account.
     */
    case Codex = 'codex';

    /**
     * Human-readable label shown by Filament badges.
     *
     * @return string
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Claude => 'Claude',
            self::Codex => 'Codex',
        };
    }

    /**
     * Badge color used by Filament table columns.
     *
     * @return string
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Claude => 'primary',
            self::Codex => 'success',
        };
    }
}

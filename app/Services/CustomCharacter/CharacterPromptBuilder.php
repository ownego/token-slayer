<?php

namespace App\Services\CustomCharacter;

use Illuminate\Support\Str;

/**
 * Builds the prompt a player pastes into an image AI, one per character type
 * (warrior, wizard, assassin, ...). Every prompt spells out that class's
 * costume (body, waist, feet, cape, weapon, hat) so the AI draws a recognizable
 * class rather than a generic adventurer, and leaves the COSTUME ITEMS block
 * empty for the player to override item by item. The wording lives in
 * `config('token_slayer.custom_character')`: the shared `prompt` template and
 * the per-class `types`.
 */
class CharacterPromptBuilder
{
    /**
     * The costume items every type defines, in the order the prompt lists them.
     *
     * @var list<string>
     */
    private const array ITEMS = ['body', 'waist', 'feet', 'cape', 'weapon', 'hat'];

    /**
     * Builds the prompt of every character type.
     *
     * @return array<string, string> the prompt text keyed by character type, in picker order
     */
    public function execute(): array
    {
        $template = (string) config('token_slayer.custom_character.prompt');

        return collect((array) config('token_slayer.custom_character.types'))
            ->map(fn (array $type): string => $this->fill($template, $type))
            ->all();
    }

    /**
     * Fills the template with one character type's wording.
     *
     * @param  string  $template  the shared prompt template
     * @param  array<string, mixed>  $type  one entry of the configured character types
     * @return string
     */
    private function fill(string $template, array $type): string
    {
        $label = (string) $type['label'];
        $costume = collect(self::ITEMS)
            ->map(fn (string $item): string => '- '.Str::ucfirst($item).': '.$type['costume'][$item])
            ->implode("\n");

        return strtr($template, [
            '{LABEL}' => Str::upper($label),
            '{label}' => Str::lower($label),
            '{NOT_GENERIC}' => (string) $type['not_generic'],
            '{COSTUME}' => $costume,
            '{IDENTITY}' => (string) $type['identity'],
            '{AVOID}' => (string) $type['avoid'],
            '{ATTACK}' => (string) $type['attack'],
        ]);
    }
}

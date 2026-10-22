<?php

namespace App\Services\CustomCharacter;

use Illuminate\Support\Str;

/**
 * Builds the prompt a player pastes into an image AI, one per character type
 * (warrior, wizard, assassin, ...). Every class prompt spells out that class's
 * costume (body, waist, feet, cape, weapon, hat) so the AI draws a recognizable
 * class rather than a generic adventurer, and leaves the COSTUME ITEMS block
 * empty for the player to override item by item. The `custom` type is the
 * exception: it has no class and no costume, only the retro pixel style and the
 * output, so a character that is not fantasy (an idol, a cartoon character) stays
 * as it is. The wording lives in `config('token_slayer.custom_character')`: the
 * shared `prompt` template (and `prompt_custom`), the shared style and output
 * pieces, and the per-type `types`.
 */
class CharacterPromptBuilder
{
    /**
     * The costume items every class type defines, in the order the prompt lists them.
     *
     * @var list<string>
     */
    private const array ITEMS = ['body', 'waist', 'feet', 'cape', 'weapon', 'hat'];

    /**
     * The value of a type's `template` that selects the class-free prompt.
     *
     * @var string
     */
    private const string CUSTOM = 'custom';

    /**
     * Builds the prompt of every character type.
     *
     * @return array<string, string> the prompt text keyed by character type, in picker order
     */
    public function execute(): array
    {
        return collect((array) config('token_slayer.custom_character.types'))
            ->map(fn (array $type): string => $this->fill($type))
            ->all();
    }

    /**
     * Fills the right template with one character type's wording.
     *
     * @param  array<string, mixed>  $type  one entry of the configured character types
     * @return string
     */
    private function fill(array $type): string
    {
        $custom = ($type['template'] ?? null) === self::CUSTOM;
        $template = (string) config($custom ? 'token_slayer.custom_character.prompt_custom' : 'token_slayer.custom_character.prompt');
        $shared = [
            '{PIXEL_ART_STYLE}' => $this->style($custom),
            '{OUTPUT}' => strtr((string) config('token_slayer.custom_character.prompt_output'), ['{ATTACK}' => (string) $type['attack']]),
        ];

        return strtr($template, $custom ? $shared : [...$shared, ...$this->classWording($type)]);
    }

    /**
     * The pixel art style block, with the wording of the class prompts or the neutral one of Custom.
     *
     * @param  bool  $custom  whether it is for the Custom prompt
     * @return string
     */
    private function style(bool $custom): string
    {
        return strtr(
            (string) config('token_slayer.custom_character.prompt_pixel_style'),
            (array) config('token_slayer.custom_character.prompt_style_lines.'.($custom ? 'custom' : 'class')),
        );
    }

    /**
     * What a class type puts into the class template.
     *
     * @param  array<string, mixed>  $type  one entry of the configured character types
     * @return array<string, string> placeholder => text
     */
    private function classWording(array $type): array
    {
        $label = (string) $type['label'];
        $costume = collect(self::ITEMS)
            ->map(fn (string $item): string => '- '.Str::ucfirst($item).': '.$type['costume'][$item])
            ->implode("\n");

        return [
            '{LABEL}' => Str::upper($label),
            '{label}' => Str::lower($label),
            '{NOT_GENERIC}' => (string) $type['not_generic'],
            '{COSTUME}' => $costume,
            '{IDENTITY}' => (string) $type['identity'],
            '{AVOID}' => (string) $type['avoid'],
        ];
    }
}

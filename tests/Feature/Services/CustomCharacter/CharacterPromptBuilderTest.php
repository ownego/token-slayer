<?php

use App\Services\CustomCharacter\CharacterPromptBuilder;

const PROMPT_TYPES = ['warrior', 'wizard', 'assassin', 'archer', 'healer', 'necromancer', 'alchemist', 'berserker'];

const BLANK_ITEMS = "- Body:\n- Waist:\n- Feet:\n- Cape:\n- Weapon:\n- Hat:";

test('there is a prompt for every character type, in the order the picker shows them, with Custom last', function () {
    expect(array_keys(app(CharacterPromptBuilder::class)->execute()))->toBe([...PROMPT_TYPES, 'custom']);
});

test('the picker labels come from the same config as the prompts', function () {
    $types = [...PROMPT_TYPES, 'custom'];

    expect(collect($types)->mapWithKeys(fn (string $type) => [$type => config("token_slayer.custom_character.types.{$type}.label")])->all())
        ->toBe(array_combine($types, array_map('ucfirst', $types)));
});

test('there is no knight to pick any more', function () {
    expect(config('token_slayer.custom_character.types'))->not->toHaveKey('knight');
    expect(file_exists(public_path('assets/custom-character/types/knight.png')))->toBeFalse();
});

test('each prompt names its type as the authority for the costume', function (string $type) {
    $prompt = app(CharacterPromptBuilder::class)->execute()[$type];
    $label = strtoupper($type);

    expect($prompt)->toContain("CHARACTER TYPE: {$label}")
        ->toContain('primary authority for costume design')
        ->toContain("{$label} VISUAL IDENTITY")
        ->toContain('COSTUME CONSISTENCY');
})->with(PROMPT_TYPES);

test('each prompt defines the type\'s own body, waist, feet, cape, weapon and hat', function (string $type) {
    $prompt = app(CharacterPromptBuilder::class)->execute()[$type];

    foreach (['Body', 'Waist', 'Feet', 'Cape', 'Weapon', 'Hat'] as $item) {
        expect($prompt)->toMatch("/^- {$item}: \S.{40,}/m");
    }
})->with(PROMPT_TYPES);

test('each prompt leaves the costume items block empty for the user to fill in', function (string $type) {
    $prompt = app(CharacterPromptBuilder::class)->execute()[$type];

    expect($prompt)->toContain("COSTUME ITEMS (optional")
        ->toContain("\n".BLANK_ITEMS."\n")
        ->and(substr_count($prompt, BLANK_ITEMS))->toBe(1);
})->with(PROMPT_TYPES);

test('each prompt keeps the sheet layout the converter needs', function (string $type) {
    $prompt = app(CharacterPromptBuilder::class)->execute()[$type];

    expect($prompt)->toContain('#FF00FF')
        ->toContain('Row 1: idle')
        ->toContain('Row 2: run cycle')
        ->toContain('Row 3: ')
        ->toContain('Row 4: defeated')
        ->toContain('facing RIGHT');
})->with(PROMPT_TYPES);

test('no prompt is left with an unfilled placeholder', function (string $type) {
    expect(app(CharacterPromptBuilder::class)->execute()[$type])->not->toMatch('/\{[A-Za-z_]+\}/');
})->with(PROMPT_TYPES);

test('the prompts differ: no two types share a costume, and Custom is its own prompt', function () {
    $prompts = app(CharacterPromptBuilder::class)->execute();

    expect(array_unique(array_map(fn (string $p) => md5(preg_replace('/\b[A-Z]{4,}\b/', '', $p)), $prompts)))->toHaveCount(count(PROMPT_TYPES) + 1);
});

test('each type carries the weapon that makes it recognisable', function (string $type, string $weapon) {
    expect(strtolower(app(CharacterPromptBuilder::class)->execute()[$type]))->toContain($weapon);
})->with([
    'warrior' => ['warrior', 'sword'],
    'wizard' => ['wizard', 'staff'],
    'assassin' => ['assassin', 'dagger'],
    'archer' => ['archer', 'bow'],
    'healer' => ['healer', 'staff'],
    'necromancer' => ['necromancer', 'skull'],
    'alchemist' => ['alchemist', 'flask'],
    'berserker' => ['berserker', 'axe'],
]);

test('the assassin follows the supplied design: dark leather, dagger, short crimson cloak, hood', function () {
    $prompt = app(CharacterPromptBuilder::class)->execute()['assassin'];

    expect($prompt)->toContain('charcoal')
        ->toContain('dagger')
        ->toContain('crimson')
        ->toContain('hood')
        ->toContain('Do NOT give the assassin');
});

test('the third row asks for the type\'s own attack', function () {
    expect(app(CharacterPromptBuilder::class)->execute()['archer'])->toMatch('/Row 3: .*(arrow|bow)/i')
        ->and(app(CharacterPromptBuilder::class)->execute()['assassin'])->toMatch('/Row 3: .*dagger/i');
});

test('signature features are preserved in every prompt, with any accessory kept visible', function (string $type) {
    expect(app(CharacterPromptBuilder::class)->execute()[$type])
        ->toContain('SIGNATURE CHARACTER FEATURE PRESERVATION')
        ->toContain('signature accessory visible in every pose');
})->with(PROMPT_TYPES);

test('every prompt carries the pixel art style block', function (string $type) {
    expect(app(CharacterPromptBuilder::class)->execute()[$type])
        ->toContain("PIXEL ART STYLE:\n- Authentic retro RPG sprite art")
        ->toContain('Small, compact, chibi-like sprite proportions')
        ->toContain('No anti-aliasing')
        ->toContain('approximately 2–4 shades per material')
        ->toContain('same slightly angled view for the idle and run poses')
        ->toContain('NOT a modern high-resolution illustration')
        ->toContain('consistent pixel scale, outline thickness, palette, proportions, and detail density');
})->with(PROMPT_TYPES);

test('the second row is a fast run, drawn at the same angle as the idle poses, not in a strict side profile', function (string $type) {
    $prompt = app(CharacterPromptBuilder::class)->execute()[$type];

    expect($prompt)->toContain('Row 2: run cycle (a fast run, not a walk: leaning forward, long strides, arms pumping), in the same slightly angled view as the idle poses, exactly 4 poses')
        ->not->toContain('pure side profile')
        ->not->toContain('strict side profile')
        ->toContain('never draw the run cycle in a strict side-on profile');
})->with(PROMPT_TYPES);

test('the run cycle spells out the leg swap, so the legs alternate and it reads as running', function (string $type) {
    expect(app(CharacterPromptBuilder::class)->execute()[$type])
        ->toContain('The legs must clearly alternate')
        ->toContain('pose 1: one leg stretched far forward and the other far back')
        ->toContain('pose 2: both feet off the ground')
        ->toContain('pose 3: the same as pose 1 with the legs swapped')
        ->toContain('pose 4: both feet off the ground again')
        ->toContain('arms swing opposite to the legs')
        ->toContain('No two poses may look alike');
})->with(PROMPT_TYPES);

test('effects are welcome but leave clear space, so poses never touch and the converter can tell them apart', function (string $type) {
    $prompt = app(CharacterPromptBuilder::class)->execute()[$type];

    expect($prompt)->toContain('Keep every pose and everything it carries or casts')
        ->toContain('Effects are welcome, but they must not reach another pose')
        ->toContain('at least 5% of the sheet width of clear empty space')
        ->toContain('effect included')
        ->toContain('No two poses may touch or overlap')
        ->not->toContain('never draw a magic circle');
})->with(PROMPT_TYPES);

test('the pixel art style block is identical for every class type', function () {
    $blocks = array_map(function (string $prompt): string {
        preg_match('/PIXEL ART STYLE:\n(?:- .*\n?)+/', $prompt, $match);

        return $match[0] ?? '';
    }, array_intersect_key(app(CharacterPromptBuilder::class)->execute(), array_flip(PROMPT_TYPES)));

    expect(array_unique($blocks))->toHaveCount(1)
        ->and(reset($blocks))->toContain('- Every pose must look like the same unified game sprite set');
});

/**
 * The Custom prompt, and its style block.
 *
 * @return array{0: string, 1: string}
 */
function customPrompt(): array
{
    $prompt = app(CharacterPromptBuilder::class)->execute()['custom'];
    preg_match('/PIXEL ART STYLE:\n(?:- .*\n?)+/', $prompt, $match);

    return [$prompt, $match[0] ?? ''];
}

test('the custom prompt says nothing about a class, a costume or fantasy: only the retro pixel style and the output', function () {
    [$prompt] = customPrompt();

    expect($prompt)->toContain('retro pixel-art')
        ->toContain('PIXEL ART STYLE:')
        ->toContain('OUTPUT: ONE image')
        ->not->toContain('CHARACTER TYPE')
        ->not->toContain('COSTUME')
        ->not->toContain('VISUAL IDENTITY')
        ->not->toContain('medieval')
        ->not->toContain('fantasy')
        ->not->toContain('warrior')
        ->not->toMatch('/\{[A-Za-z_]+\}/');
});

test('the custom prompt keeps the character exactly as drawn and adds nothing to it', function () {
    [$prompt] = customPrompt();

    expect($prompt)->toContain('Keep its design, outfit, colors and every signature feature exactly as in my picture')
        ->toContain('do not add a costume, armor, a class, a weapon or any item it does not already have');
});

test('the custom prompt shares the style and output rules of the others, minus the fantasy wording', function () {
    [$prompt, $style] = customPrompt();
    [$classStyle] = [preg_match('/PIXEL ART STYLE:\n(?:- .*\n?)+/', app(CharacterPromptBuilder::class)->execute()['wizard'], $m) ? $m[0] : ''];

    expect($style)->toContain('Small, compact, chibi-like sprite proportions')
        ->toContain('No anti-aliasing')
        ->toContain('approximately 2–4 shades per material')
        ->toContain('same slightly angled view for the idle and run poses')
        ->toContain('consistent pixel scale, outline thickness, palette, proportions, and detail density')
        ->not->toContain('fantasy')
        ->not->toContain('medieval')
        ->not->toContain('helmets')
        ->and(substr_count($classStyle, "\n"))->toBe(substr_count($style, "\n"));

    expect($prompt)->toContain('Row 2: run cycle')
        ->toContain('The legs must clearly alternate')
        ->toContain('Effects are welcome, but they must not reach another pose')
        ->toContain('#FF00FF')
        ->toContain('facing RIGHT');
});

test('the custom prompt asks for a simple attack using only what the character already has', function () {
    [$prompt] = customPrompt();

    expect($prompt)->toMatch('/Row 3: .*already has/i')->toContain('exactly 3 poses (wind up, strike, follow through)');
});

test('the colors line does not fight the style block over how many shades to use', function (string $type) {
    expect(app(CharacterPromptBuilder::class)->execute()[$type])
        ->not->toContain('at most 2 tones')
        ->not->toContain('Thick dark outline');
})->with(PROMPT_TYPES);

test('the style block sits before the output layout, so the layout stays the last word', function () {
    $prompt = app(CharacterPromptBuilder::class)->execute()['wizard'];

    expect(strpos($prompt, 'PIXEL ART STYLE:'))->toBeLessThan(strpos($prompt, 'OUTPUT: '));
});

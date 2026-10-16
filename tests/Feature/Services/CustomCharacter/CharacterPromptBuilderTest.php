<?php

use App\Services\CustomCharacter\CharacterPromptBuilder;

const PROMPT_TYPES = ['warrior', 'wizard', 'assassin', 'archer', 'healer', 'necromancer', 'knight', 'alchemist', 'berserker'];

const BLANK_ITEMS = "- Body:\n- Waist:\n- Feet:\n- Cape:\n- Weapon:\n- Hat:";

test('there is a prompt for every character type, in the order the picker shows them', function () {
    expect(array_keys(app(CharacterPromptBuilder::class)->execute()))->toBe(PROMPT_TYPES);
});

test('the picker labels come from the same config as the prompts', function () {
    expect(collect(PROMPT_TYPES)->mapWithKeys(fn (string $type) => [$type => config("token_slayer.custom_character.types.{$type}.label")])->all())
        ->toBe(array_combine(PROMPT_TYPES, array_map('ucfirst', PROMPT_TYPES)));
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
        ->toContain('Row 2: walk cycle')
        ->toContain('Row 3: ')
        ->toContain('Row 4: defeated')
        ->toContain('facing RIGHT');
})->with(PROMPT_TYPES);

test('no prompt is left with an unfilled placeholder', function (string $type) {
    expect(app(CharacterPromptBuilder::class)->execute()[$type])->not->toMatch('/\{[A-Za-z_]+\}/');
})->with(PROMPT_TYPES);

test('the prompts differ: no two types share a costume', function () {
    $prompts = app(CharacterPromptBuilder::class)->execute();

    expect(array_unique(array_map(fn (string $p) => md5(preg_replace('/\b[A-Z]{4,}\b/', '', $p)), $prompts)))->toHaveCount(count(PROMPT_TYPES));
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
    'knight' => ['knight', 'plate'],
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
        ->toContain('strict side profile')
        ->toContain('NOT a modern high-resolution illustration')
        ->toContain('consistent pixel scale, outline thickness, palette, proportions, and detail density');
})->with(PROMPT_TYPES);

test('the pixel art style block is identical for every type', function () {
    $blocks = array_map(function (string $prompt): string {
        preg_match('/PIXEL ART STYLE:\n(?:- .*\n?)+/', $prompt, $match);

        return $match[0] ?? '';
    }, app(CharacterPromptBuilder::class)->execute());

    expect(array_unique($blocks))->toHaveCount(1)
        ->and(reset($blocks))->toContain('- Every pose must look like the same unified game sprite set');
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

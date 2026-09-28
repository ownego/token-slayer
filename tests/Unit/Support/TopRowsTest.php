<?php

use App\Support\TopRows;

test('the By-model list keeps the top rows and folds the rest into one "other" row, so it never pushes Clawd off the sidebar', function () {
    $rows = [
        ['model' => 'a', 'family' => 'opus', 'damage' => 50, 'share' => 0.5],
        ['model' => 'b', 'family' => 'sonnet', 'damage' => 20, 'share' => 0.2],
        ['model' => 'c', 'family' => 'haiku', 'damage' => 15, 'share' => 0.15],
        ['model' => 'd', 'family' => 'gpt', 'damage' => 10, 'share' => 0.1],
        ['model' => 'e', 'family' => null, 'damage' => 5, 'share' => 0.05],
    ];

    $folded = TopRows::fold($rows, 3);

    expect(array_column($folded, 'model'))->toBe(['a', 'b', 'c', 'other'])
        ->and(end($folded))->toMatchArray(['family' => null, 'damage' => 15, 'share' => 0.15]);
});

test('a short list is left alone', function () {
    $rows = [['model' => 'a', 'family' => 'opus', 'damage' => 1, 'share' => 1.0]];

    expect(TopRows::fold($rows, 3))->toBe($rows);
});

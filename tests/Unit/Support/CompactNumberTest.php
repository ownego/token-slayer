<?php

use App\Support\CompactNumber;

test('formats like the fighter sheet mockup\'s own fmt()', function (int $n, string $expected) {
    expect(CompactNumber::format($n))->toBe($expected);
})->with([
    'under a thousand' => [949, '949'],
    'thousands round to a whole K' => [780_400, '780K'],
    'millions keep up to two decimals' => [1_260_000, '1.26M'],
    'trailing zeros dropped' => [7_000_000, '7M'],
    'billions' => [2_150_000_000, '2.15B'],
    'zero' => [0, '0'],
]);

<?php

use App\Services\BossNameGenerator;

test('returns a non-empty string from the pool', function () {
    $name = (new BossNameGenerator)->next();

    expect($name)->toBeString()->not->toBeEmpty();
});

test('avoids names listed as recent when possible', function () {
    $generator = new BossNameGenerator;
    $recent = ['Smaug', 'Tiamat', 'Cthulhu', 'Bahamut', 'Dracula'];

    for ($i = 0; $i < 20; $i++) {
        expect($generator->next($recent))->not->toBeIn($recent);
    }
});

test('falls back to the full pool when every name has been used recently', function () {
    $generator = new BossNameGenerator;
    $reflection = new ReflectionClass(BossNameGenerator::class);
    $pool = $reflection->getReflectionConstant('POOL')->getValue();

    $name = $generator->next($pool);

    expect($pool)->toContain($name);
});

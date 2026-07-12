<?php

use Filament\Support\Facades\FilamentTimezone;

it('displays admin datetimes in UTC+7 while keeping storage in UTC', function () {
    // Storage/parse timezone must stay UTC so existing timestamps are unaffected.
    expect(config('app.timezone'))->toBe('UTC');

    // Filament's global display timezone drives every dateTime column/picker.
    expect(FilamentTimezone::get())->toBe('Asia/Ho_Chi_Minh');
});

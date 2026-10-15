<?php

use Illuminate\Support\Facades\Storage;

test('the r2 disk is an s3 disk with no ACLs, a loud failure mode and a stalled-call timeout', function () {
    $disk = config('filesystems.disks.r2');

    expect($disk['driver'])->toBe('s3')
        ->and($disk['region'])->toBe('auto')
        ->and($disk['throw'])->toBeTrue()
        ->and($disk)->not->toHaveKey('visibility')
        ->and($disk['http']['connect_timeout'])->toBeInt()->toBeGreaterThan(0)
        ->and($disk['http']['timeout'])->toBeInt()->toBeGreaterThan(0);
});

test('objects written to the r2 disk are cached for a year as immutable', function () {
    expect(config('filesystems.disks.r2.options.CacheControl'))->toBe('public, max-age=31536000, immutable');
});

test('a url on the r2 disk is built from the bucket\'s public domain, not from the storage endpoint', function () {
    config([
        'filesystems.disks.r2.key' => 'key',
        'filesystems.disks.r2.secret' => 'secret',
        'filesystems.disks.r2.bucket' => 'a-bucket',
        'filesystems.disks.r2.endpoint' => 'https://account.r2.cloudflarestorage.com',
        'filesystems.disks.r2.url' => 'https://media.example.test',
    ]);
    Storage::forgetDisk('r2');

    expect(Storage::disk('r2')->url('folder/file.png'))->toBe('https://media.example.test/folder/file.png');
});

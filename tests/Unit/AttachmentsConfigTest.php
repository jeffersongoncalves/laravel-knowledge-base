<?php

it('stores attachments under one knowledge base directory on the private local disk by default', function () {
    expect(config('knowledge-base.attachments'))->toBe([
        'disk' => 'local',
        'directory' => 'knowledge-base',
        'route' => [
            'enabled' => true,
            'prefix' => 'knowledge-base/assets',
            'middleware' => [],
            'signed_url_minutes' => 30,
            'cache_max_age' => 3600,
        ],
        'images' => [
            'accepted_types' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_size' => 5120,
        ],
        'files' => [
            'accepted_types' => ['application/pdf'],
            'max_size' => 10240,
        ],
    ]);
});

it('allows the accepted types and sizes to be configured per kind', function () {
    config([
        'knowledge-base.attachments.images.accepted_types' => ['image/jpeg', 'image/avif'],
        'knowledge-base.attachments.images.max_size' => 1024,
    ]);

    expect(config('knowledge-base.attachments.images.accepted_types'))->toBe(['image/jpeg', 'image/avif'])
        ->and(config('knowledge-base.attachments.images.max_size'))->toBe(1024);
});

<?php

it('stores attachments under one knowledge base directory on the public disk by default', function () {
    expect(config('knowledge-base.attachments'))->toBe([
        'disk' => 'public',
        'directory' => 'knowledge-base/attachments',
    ]);
});

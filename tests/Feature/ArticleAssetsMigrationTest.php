<?php

use Illuminate\Support\Facades\Schema;

function addArticleAssetsMigration(): object
{
    return require __DIR__.'/../../database/migrations/add_kb_article_assets.php.stub';
}

afterEach(function () {
    if (! Schema::hasTable('kb_article_assets')) {
        addArticleAssetsMigration()->up();
    }
});

it('creates the article assets table with one row per file', function () {
    expect(Schema::hasTable('kb_article_assets'))->toBeTrue();

    $columns = Schema::getColumnListing('kb_article_assets');

    expect($columns)->toContain(
        'uuid',
        'article_id',
        'kind',
        'locale',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'width',
        'height',
    );
});

it('rolls back and reapplies', function () {
    $migration = addArticleAssetsMigration();

    $migration->down();

    expect(Schema::hasTable('kb_article_assets'))->toBeFalse();

    $migration->up();

    expect(Schema::hasTable('kb_article_assets'))->toBeTrue();
});

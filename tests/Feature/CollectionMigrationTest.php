<?php

use Illuminate\Support\Facades\Schema;

/**
 * Load a fresh instance of the collections migration so its up() and down() can
 * be exercised (see task 1.1: it migrates and rolls back on SQLite and
 * PostgreSQL).
 */
function collectionsMigration(): object
{
    return require __DIR__.'/../../database/migrations/add_kb_collections.php.stub';
}

beforeEach(function () {
    $this->migration = collectionsMigration();
});

afterEach(function () {
    // down()/up() change the real schema; make sure the migrated schema is in
    // place again even if an assertion failed halfway.
    $this->migration->up();
});

it('creates the collection tables', function () {
    expect(Schema::hasTable('kb_collections'))->toBeTrue()
        ->and(Schema::hasTable('kb_collection_translations'))->toBeTrue()
        ->and(Schema::hasTable('kb_collection_article'))->toBeTrue();
});

it('creates the collection unique keys', function () {
    $translationUniques = collect(Schema::getIndexes('kb_collection_translations'))
        ->filter(fn ($index) => $index['unique'])
        ->map(fn ($index) => $index['columns'])
        ->all();

    expect($translationUniques)->toContain(['collection_id', 'locale'])
        ->toContain(['locale', 'slug']);

    $membershipUniques = collect(Schema::getIndexes('kb_collection_article'))
        ->filter(fn ($index) => $index['unique'])
        ->map(fn ($index) => $index['columns'])
        ->all();

    expect($membershipUniques)->toContain(['collection_id', 'article_id']);
});

it('drops everything on rollback', function () {
    $this->migration->down();

    expect(Schema::hasTable('kb_collections'))->toBeFalse()
        ->and(Schema::hasTable('kb_collection_translations'))->toBeFalse()
        ->and(Schema::hasTable('kb_collection_article'))->toBeFalse();
});

it('is idempotent when run twice', function () {
    $this->migration->up();

    expect(Schema::hasTable('kb_collections'))->toBeTrue()
        ->and(Schema::hasTable('kb_collection_translations'))->toBeTrue()
        ->and(Schema::hasTable('kb_collection_article'))->toBeTrue();
});

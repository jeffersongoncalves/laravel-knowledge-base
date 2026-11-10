<?php

use Illuminate\Support\Facades\Schema;
use JeffersonGoncalves\KnowledgeBase\KnowledgeBaseServiceProvider;
use JeffersonGoncalves\PestPluginDatabaseMigrations\PackageMigrations;

function addArticleSortOrderMigration(): object
{
    $files = PackageMigrations::files(app(), KnowledgeBaseServiceProvider::class);

    // The stub the provider declares, so the test fails if it is not registered.
    return require collect($files)->first(fn (string $file) => str_contains($file, 'add_kb_article_sort_order'));
}

afterEach(function () {
    if (! Schema::hasColumn('kb_articles', 'sort_order')) {
        addArticleSortOrderMigration()->up();
    }
});

it('adds sort_order to articles', function () {
    expect(Schema::hasColumn('kb_articles', 'sort_order'))->toBeTrue();
});

it('rolls back and reapplies', function () {
    $migration = addArticleSortOrderMigration();

    $migration->down();

    expect(Schema::hasColumn('kb_articles', 'sort_order'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('kb_articles', 'sort_order'))->toBeTrue();
});

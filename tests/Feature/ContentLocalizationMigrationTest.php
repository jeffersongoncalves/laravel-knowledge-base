<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Load a fresh instance of the localization migration so the upgrade path
 * (and its down()) can be exercised against a legacy schema.
 */
function localizationMigration(): object
{
    return require __DIR__.'/../../database/migrations/add_kb_content_localization.php.stub';
}

/**
 * Insert an article row as pre-localization code would have written it,
 * bypassing the models (and therefore the mirror and backfill events).
 */
function legacyArticle(string $prefix, int $categoryId, array $overrides = []): int
{
    $slug = $overrides['slug'] ?? 'legacy-article';

    return DB::table($prefix.'articles')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(),
        'category_id' => $categoryId,
        'title' => 'Legacy article',
        'slug' => $slug,
        'content' => 'Legacy content',
        'excerpt' => 'Legacy excerpt',
        'author_type' => 'user',
        'author_id' => 1,
        'status' => 'published',
        'visibility' => 'public',
        'seo_title' => 'Legacy SEO title',
        'seo_description' => 'Legacy SEO description',
        'seo_keywords' => 'legacy',
        'view_count' => 0,
        'helpful_count' => 0,
        'not_helpful_count' => 0,
        'published_at' => now(),
        'current_version' => 1,
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function legacyCategory(string $prefix, array $overrides = []): int
{
    return DB::table($prefix.'categories')->insertGetId(array_merge([
        'parent_id' => null,
        'name' => 'Legacy category',
        'slug' => 'legacy-category',
        'description' => 'Legacy description',
        'icon' => null,
        'visibility' => 'public',
        'is_active' => true,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

beforeEach(function () {
    $this->prefix = 'kb_';
    $this->migration = localizationMigration();
});

afterEach(function () {
    // Down()/up() change the real schema; make sure the migrated schema is in
    // place again even if an assertion failed halfway.
    $this->migration->up();
});

it('upgrades a single-language knowledge base', function () {
    $this->migration->down();

    $categoryId = legacyCategory($this->prefix);
    $articleId = legacyArticle($this->prefix, $categoryId);
    DB::table($this->prefix.'article_versions')->insert([
        'article_id' => $articleId,
        'version_number' => 1,
        'title' => 'Legacy article',
        'content' => 'Legacy content',
        'excerpt' => 'Legacy excerpt',
        'editor_type' => 'user',
        'editor_id' => 1,
        'change_notes' => 'Initial version',
        'created_at' => now(),
    ]);

    config(['knowledge-base.locales' => ['en'], 'knowledge-base.default_locale' => 'en']);

    $this->migration->up();

    $articleTranslation = DB::table($this->prefix.'article_translations')->where('article_id', $articleId)->first();

    expect($articleTranslation)->not->toBeNull()
        ->and($articleTranslation->locale)->toBe('en')
        ->and($articleTranslation->title)->toBe('Legacy article')
        ->and($articleTranslation->slug)->toBe('legacy-article')
        ->and($articleTranslation->content)->toBe('Legacy content')
        ->and($articleTranslation->excerpt)->toBe('Legacy excerpt')
        ->and($articleTranslation->seo_title)->toBe('Legacy SEO title')
        ->and($articleTranslation->status)->toBe('published')
        ->and((bool) $articleTranslation->is_outdated)->toBeFalse();

    $categoryTranslation = DB::table($this->prefix.'category_translations')->where('category_id', $categoryId)->first();

    expect($categoryTranslation)->not->toBeNull()
        ->and($categoryTranslation->locale)->toBe('en')
        ->and($categoryTranslation->name)->toBe('Legacy category')
        ->and($categoryTranslation->slug)->toBe('legacy-category');

    expect(DB::table($this->prefix.'articles')->where('id', $articleId)->value('source_locale'))->toBe('en');
    expect(DB::table($this->prefix.'article_versions')->where('article_id', $articleId)->value('locale'))->toBe('en');
});

it('upgrades content written in another language', function () {
    $this->migration->down();

    $categoryId = legacyCategory($this->prefix, ['name' => 'Abrechnung', 'slug' => 'abrechnung']);
    $articleId = legacyArticle($this->prefix, $categoryId, [
        'title' => 'Rechnungen herunterladen',
        'slug' => 'rechnungen-herunterladen',
    ]);

    config(['knowledge-base.locales' => ['de', 'en'], 'knowledge-base.default_locale' => 'de']);

    $this->migration->up();

    $articleTranslation = DB::table($this->prefix.'article_translations')->where('article_id', $articleId)->first();
    $categoryTranslation = DB::table($this->prefix.'category_translations')->where('category_id', $categoryId)->first();

    expect($articleTranslation->locale)->toBe('de')
        ->and($articleTranslation->title)->toBe('Rechnungen herunterladen')
        ->and($categoryTranslation->locale)->toBe('de')
        ->and($categoryTranslation->name)->toBe('Abrechnung');

    expect(DB::table($this->prefix.'articles')->where('id', $articleId)->value('source_locale'))->toBe('de');
});

it('does not duplicate content when the upgrade runs twice', function () {
    $this->migration->down();

    $categoryId = legacyCategory($this->prefix);
    $articleId = legacyArticle($this->prefix, $categoryId);

    $this->migration->up();
    $this->migration->up();

    expect(DB::table($this->prefix.'article_translations')->where('article_id', $articleId)->count())->toBe(1)
        ->and(DB::table($this->prefix.'category_translations')->where('category_id', $categoryId)->count())->toBe(1);
});

it('restores the previous schema on rollback', function () {
    $this->migration->down();

    expect(Schema::hasTable($this->prefix.'article_translations'))->toBeFalse()
        ->and(Schema::hasTable($this->prefix.'category_translations'))->toBeFalse()
        ->and(Schema::hasColumn($this->prefix.'articles', 'source_locale'))->toBeFalse()
        ->and(Schema::hasColumn($this->prefix.'article_versions', 'locale'))->toBeFalse();
});

it('creates the translation unique keys', function () {
    $articleUniques = collect(Schema::getIndexes('kb_article_translations'))
        ->filter(fn ($index) => $index['unique'])
        ->map(fn ($index) => $index['columns'])
        ->all();

    expect($articleUniques)->toContain(['article_id', 'locale'])
        ->toContain(['locale', 'slug']);

    $categoryUniques = collect(Schema::getIndexes('kb_category_translations'))
        ->filter(fn ($index) => $index['unique'])
        ->map(fn ($index) => $index['columns'])
        ->all();

    expect($categoryUniques)->toContain(['category_id', 'locale'])
        ->toContain(['locale', 'slug']);
});

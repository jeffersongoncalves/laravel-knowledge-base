<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Load a fresh instance of the migration that drops kb_articles.source_locale,
 * so the upgrade path (and its down()) can be exercised against a legacy
 * schema.
 */
function removeSourceLocaleMigration(): object
{
    return require __DIR__.'/../../database/migrations/remove_kb_article_source_locale.php.stub';
}

function legacyCategoryRow(array $overrides = []): int
{
    return DB::table('kb_categories')->insertGetId(array_merge([
        'parent_id' => null,
        'name' => 'Legacy category',
        'slug' => 'legacy-category-'.Str::random(6),
        'description' => null,
        'icon' => null,
        'visibility' => 'public',
        'is_active' => true,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function legacyArticleRow(int $categoryId, array $overrides = []): int
{
    return DB::table('kb_articles')->insertGetId(array_merge([
        'uuid' => (string) Str::uuid(),
        'category_id' => $categoryId,
        'title' => 'Legacy title',
        'slug' => 'legacy-article-'.Str::random(6),
        'content' => 'Legacy content',
        'excerpt' => 'Legacy excerpt',
        'author_type' => 'user',
        'author_id' => 1,
        'status' => 'published',
        'visibility' => 'public',
        'source_locale' => 'en',
        'seo_title' => null,
        'seo_description' => null,
        'seo_keywords' => null,
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

function legacyTranslationRow(int $articleId, string $locale, array $overrides = []): void
{
    DB::table('kb_article_translations')->insert(array_merge([
        'article_id' => $articleId,
        'locale' => $locale,
        'title' => "Title {$locale}",
        'slug' => "title-{$locale}",
        'content' => "Content {$locale}",
        'excerpt' => null,
        'seo_title' => null,
        'seo_description' => null,
        'seo_keywords' => null,
        'status' => 'published',
        'published_at' => now(),
        'is_outdated' => false,
        'outdated_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

beforeEach(function () {
    config(['knowledge-base.locales' => ['en', 'de'], 'knowledge-base.default_locale' => 'en']);
    $this->migration = removeSourceLocaleMigration();
    // The migrated schema has no source_locale; put the legacy column back.
    $this->migration->down();
});

afterEach(function () {
    // up()/down() change the real schema; make sure the column is gone again
    // even if an assertion failed halfway (DDL is not transactional on MySQL).
    if (Schema::hasColumn('kb_articles', 'source_locale')) {
        Schema::table('kb_articles', function (Blueprint $table) {
            $table->dropColumn('source_locale');
        });
    }
});

it('promotes the default-language version of an article with another authoritative language', function () {
    $categoryId = legacyCategoryRow();
    $articleId = legacyArticleRow($categoryId, [
        'source_locale' => 'de',
        'title' => 'Rechnungen',
        'slug' => 'rechnungen',
    ]);

    legacyTranslationRow($articleId, 'en', ['title' => 'Invoices', 'slug' => 'invoices', 'status' => 'draft']);
    legacyTranslationRow($articleId, 'de', ['title' => 'Rechnungen', 'slug' => 'rechnungen', 'status' => 'published']);

    $this->migration->up();

    $article = DB::table('kb_articles')->where('id', $articleId)->first();

    expect(Schema::hasColumn('kb_articles', 'source_locale'))->toBeFalse()
        ->and($article->title)->toBe('Invoices')
        ->and($article->slug)->toBe('invoices');

    // The other version is kept, and the promoted version follows the article.
    expect(DB::table('kb_article_translations')->where('article_id', $articleId)->count())->toBe(2);
    expect(DB::table('kb_article_translations')
        ->where('article_id', $articleId)
        ->where('locale', 'en')
        ->value('status'))->toBe('published');
});

it('stops before changing anything when an article has no default-language version', function () {
    $categoryId = legacyCategoryRow();
    $articleId = legacyArticleRow($categoryId, [
        'source_locale' => 'de',
        'title' => 'Rechnungen',
        'slug' => 'rechnungen',
    ]);

    legacyTranslationRow($articleId, 'de', ['title' => 'Rechnungen', 'slug' => 'rechnungen']);

    expect(fn () => $this->migration->up())
        ->toThrow(RuntimeException::class, (string) $articleId);

    // Nothing changed: the column is still there and the row keeps its values.
    expect(Schema::hasColumn('kb_articles', 'source_locale'))->toBeTrue()
        ->and(DB::table('kb_articles')->where('id', $articleId)->value('title'))->toBe('Rechnungen')
        ->and(DB::table('kb_articles')->where('id', $articleId)->value('source_locale'))->toBe('de');
});

it('leaves an article already in the default language untouched', function () {
    $categoryId = legacyCategoryRow();
    $articleId = legacyArticleRow($categoryId, ['source_locale' => 'en', 'slug' => 'english-article']);

    legacyTranslationRow($articleId, 'en', ['title' => 'English title', 'slug' => 'english-article']);

    $up = $this->migration;

    $up->up();

    expect(Schema::hasColumn('kb_articles', 'source_locale'))->toBeFalse()
        ->and(DB::table('kb_articles')->where('id', $articleId)->value('slug'))->toBe('english-article');
});

it('restores the column on rollback', function () {
    $this->migration->up();
    $this->migration->down();

    expect(Schema::hasColumn('kb_articles', 'source_locale'))->toBeTrue();

    $categoryId = legacyCategoryRow();
    $articleId = legacyArticleRow($categoryId, ['source_locale' => 'en']);

    expect(DB::table('kb_articles')->where('id', $articleId)->value('source_locale'))->toBe('en');
});

it('rolls back even when the default language is not one of the configured languages', function () {
    $this->migration->up();
    config(['knowledge-base.default_locale' => 'it']);

    $this->migration->down();

    expect(Schema::hasColumn('kb_articles', 'source_locale'))->toBeTrue();
});

<?php

use Illuminate\Support\Facades\DB;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;
use JeffersonGoncalves\KnowledgeBase\Support\TranslationVerifier;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'knowledge-base.locales' => ['en'],
        'knowledge-base.default_locale' => 'en',
    ]);

    User::createTable();
    $this->service = app(KnowledgeBaseService::class);
    $this->user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $this->category = Category::factory()->create(['name' => 'Account', 'slug' => 'account']);
    $this->article = $this->service->createArticle([
        'category_id' => $this->category->id,
        'title' => 'Password reset',
        'slug' => 'password-reset',
        'content' => 'How to apply.',
    ], $this->user);
});

it('reports nothing when every translation is in sync', function () {
    $this->artisan('kb:verify-translations')
        ->expectsOutputToContain('All translations are in sync.')
        ->assertSuccessful();
});

it('reports an article changed behind the package back', function () {
    DB::table('kb_articles')->where('id', $this->article->id)->update(['title' => 'Changed behind the package']);

    $this->artisan('kb:verify-translations')
        ->expectsOutputToContain("Article #{$this->article->id} [en] differs in [title].")
        ->assertFailed();
});

it('reports a category changed behind the package back', function () {
    DB::table('kb_categories')->where('id', $this->category->id)->update(['name' => 'Changed behind the package']);

    $this->artisan('kb:verify-translations')
        ->expectsOutputToContain("Category #{$this->category->id} [en] differs in [name].")
        ->assertFailed();
});

it('reports a missing authoritative version', function () {
    DB::table('kb_article_translations')->where('article_id', $this->article->id)->delete();

    $this->artisan('kb:verify-translations')
        ->expectsOutputToContain("Article #{$this->article->id} has no authoritative version in [en].")
        ->assertFailed();
});

it('ignores articles it cannot see', function () {
    // Sanity: a second, untouched article does not cause drift.
    $this->service->createArticle([
        'category_id' => $this->category->id,
        'title' => 'Another article',
        'slug' => 'another-article',
        'content' => 'Another body.',
    ], $this->user);

    expect((new TranslationVerifier)->drift())->toBe([]);
    expect(Article::count())->toBe(2);
});

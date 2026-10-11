<?php

use Illuminate\Support\Facades\DB;
use JeffersonGoncalves\KnowledgeBase\Enums\TranslationStatus;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'knowledge-base.locales' => ['en', 'de'],
        'knowledge-base.default_locale' => 'en',
        'knowledge-base.missing_translation' => 'fallback',
        'knowledge-base.search_languages' => ['en' => 'english', 'de' => 'german'],
    ]);

    User::createTable();
    $this->service = app(KnowledgeBaseService::class);
    $this->user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $this->category = Category::factory()->create(['name' => 'Account', 'slug' => 'account']);
});

function searchableArticle(string $slug, array $overrides = []): Article
{
    return test()->service->createArticle(array_merge([
        'category_id' => test()->category->id,
        'title' => 'Password reset',
        'slug' => $slug,
        'content' => 'How to reset your password.',
    ], $overrides), test()->user);
}

function addGermanTranslation(Article $article, string $title, string $content): void
{
    $article->translations()->create([
        'locale' => 'de',
        'title' => $title,
        'slug' => $article->slug.'-de',
        'content' => $content,
        'status' => TranslationStatus::Published,
    ]);
}

describe('Search per language', function () {
    it('returns a published German version with German as served language', function () {
        $article = searchableArticle('password-reset');
        $this->service->publishArticle($article);
        addGermanTranslation($article, 'Passwort zurücksetzen anfordern', 'So setzt du dein Passwort zurück.');

        $results = $this->service->search('Passwort zurücksetzen', ['locale' => 'de']);

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($article->id)
            ->and($results->first()->served_locale)->toBe('de');
    });

    it('carries the served language version on each result', function () {
        $article = searchableArticle('password-reset');
        $this->service->publishArticle($article);
        addGermanTranslation($article, 'Passwort zurücksetzen anfordern', 'So setzt du dein Passwort zurück.');

        $result = $this->service->search('Passwort zurücksetzen', ['locale' => 'de'])->first();

        expect($result->served_translation)->not->toBeNull()
            ->and($result->served_translation->getAttribute('locale'))->toBe('de')
            ->and($result->served_translation->getAttribute('title'))->toBe('Passwort zurücksetzen anfordern')
            ->and($result->title)->not->toBe('Passwort zurücksetzen anfordern');

        // Saving a search result leaves the authoritative columns alone.
        $original = $article->fresh()->title;
        $result->save();

        expect($article->fresh()->title)->toBe($original);
    });

    it('carries the authoritative version when it fell back', function () {
        $article = searchableArticle('password-reset');
        $this->service->publishArticle($article);

        $result = $this->service->search('Password', ['locale' => 'de'])->first();

        expect($result->served_translation->getAttribute('locale'))->toBe('en')
            ->and($result->served_translation->getAttribute('title'))->toBe($result->title);
    });

    it('falls back to the authoritative version and reports English', function () {
        $article = searchableArticle('password-reset');
        $this->service->publishArticle($article);

        $results = $this->service->search('Password', ['locale' => 'de']);

        expect($results)->toHaveCount(1)
            ->and($results->first()->served_locale)->toBe('en');
    });

    it('hides an English-only article when hidden behaviour is configured', function () {
        config(['knowledge-base.missing_translation' => 'hide']);

        $article = searchableArticle('password-reset');
        $this->service->publishArticle($article);

        expect($this->service->search('Password', ['locale' => 'de']))->toHaveCount(0);
    });

    it('does not fall back when a readable German version exists', function () {
        $article = searchableArticle('password-reset');
        $this->service->publishArticle($article);
        addGermanTranslation($article, 'Passwort zurücksetzen', 'Ein anderer Text ohne das gesuchte Wort.');

        // The German version exists (so no fallback) but does not match.
        expect($this->service->search('Password', ['locale' => 'de']))->toHaveCount(0);
    });

    it('searches the authoritative versions when no language is given', function () {
        $article = searchableArticle('password-reset');
        $this->service->publishArticle($article);
        addGermanTranslation($article, 'Passwort zurücksetzen', 'So funktioniert es.');

        $results = $this->service->search('Password');

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($article->id);
    });

    it('keeps the visibility rules for a language search', function () {
        $article = searchableArticle('password-reset', ['visibility' => 'internal']);
        $this->service->publishArticle($article);
        addGermanTranslation($article, 'Passwort zurücksetzen', 'Interner Inhalt.');

        expect($this->service->search('Passwort zurücksetzen', ['locale' => 'de']))->toHaveCount(0)
            ->and($this->service->search('Passwort zurücksetzen', ['locale' => 'de', 'include_internal' => true]))->toHaveCount(1);
    });

    it('matches German word forms on PostgreSQL', function () {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Language-specific stemming is a PostgreSQL text-search feature.');
        }

        $article = searchableArticle('abrechnung');
        $this->service->publishArticle($article);
        addGermanTranslation($article, 'Abrechnung', 'Informationen zu deinen Rechnungen.');

        expect($this->service->search('Rechnung', ['locale' => 'de']))->toHaveCount(1);
    });
});

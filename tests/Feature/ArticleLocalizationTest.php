<?php

use JeffersonGoncalves\KnowledgeBase\Enums\TranslationStatus;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\ArticleTranslation;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Models\CategoryTranslation;
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;
use JeffersonGoncalves\KnowledgeBase\Support\TranslationResolver;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'knowledge-base.locales' => ['en', 'de'],
        'knowledge-base.default_locale' => 'en',
        'knowledge-base.missing_translation' => 'fallback',
    ]);

    User::createTable();
    $this->service = app(KnowledgeBaseService::class);
    $this->resolver = app(TranslationResolver::class);
    $this->user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $this->category = Category::factory()->create(['name' => 'Account', 'slug' => 'account']);
});

function makeEnglishArticle(array $overrides = []): Article
{
    return test()->service->createArticle(array_merge([
        'category_id' => test()->category->id,
        'title' => 'Password reset',
        'slug' => 'password-reset',
        'content' => 'How to reset your password.',
    ], $overrides), test()->user);
}

it('resolves the translation models', function () {
    expect(ModelResolver::articleTranslation())->toBe(ArticleTranslation::class);
    expect(ModelResolver::categoryTranslation())->toBe(
        CategoryTranslation::class
    );
});

describe('Article versions per language', function () {
    it('behaves as a single-language knowledge base with one configured language', function () {
        config(['knowledge-base.locales' => ['en'], 'knowledge-base.default_locale' => 'en']);

        $article = makeEnglishArticle();
        $this->service->publishArticle($article);

        $english = $this->resolver->resolveArticle($article, 'en');
        $results = $this->service->search('Password');

        expect($article->source_locale)->toBe('en')
            ->and($article->translations)->toHaveCount(1)
            ->and($article->translations->first()->locale)->toBe('en')
            ->and($english?->locale)->toBe('en')
            ->and($results)->toHaveCount(1);
    });

    it('adds a German version that is the same article', function () {
        $article = makeEnglishArticle();

        $german = $article->translations()->create([
            'locale' => 'de',
            'title' => 'Passwort zurücksetzen',
            'slug' => 'passwort-zuruecksetzen',
            'content' => 'So setzt du dein Passwort zurück.',
        ]);

        expect($article->fresh()->translations)->toHaveCount(2)
            ->and($german->article_id)->toBe($article->id)
            ->and($german->article->category_id)->toBe($this->category->id)
            ->and($german->article->id)->toBe($article->id);
    });

    it('rejects a second version in the same language', function () {
        $article = makeEnglishArticle();

        expect(fn () => $article->translations()->create([
            'locale' => 'en',
            'title' => 'Another English version',
            'slug' => 'another-english',
            'content' => 'Duplicate.',
        ]))->toThrow(InvalidArgumentException::class);
    });

    it('rejects a version in a language that is not configured', function () {
        $article = makeEnglishArticle();

        expect(fn () => $article->translations()->create([
            'locale' => 'it',
            'title' => 'Deutsch',
            'slug' => 'deutsch',
            'content' => 'Nicht konfiguriert.',
        ]))->toThrow(InvalidArgumentException::class);
    });
});

describe('Authoritative mirror', function () {
    it('writes main-row changes to the authoritative translation', function () {
        $article = makeEnglishArticle();

        $article->update(['title' => 'Password reset (updated)']);

        expect($article->authoritativeTranslation()->title)->toBe('Password reset (updated)');
    });

    it('writes authoritative-translation changes to the main row', function () {
        $article = makeEnglishArticle();

        $article->authoritativeTranslation()->update(['title' => 'Password reset (edited)']);

        expect($article->fresh()->title)->toBe('Password reset (edited)');
    });

    it('mirrors category changes in both directions', function () {
        $category = Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);

        expect($category->authoritativeTranslation()->name)->toBe('Billing');

        $category->update(['name' => 'Billing (updated)']);
        expect($category->authoritativeTranslation()->fresh()->name)->toBe('Billing (updated)');

        $category->authoritativeTranslation()->update(['name' => 'Billing (edited)']);
        expect($category->fresh()->name)->toBe('Billing (edited)');
    });
});

describe('Authoritative language', function () {
    it('defaults a new article to the configured default language', function () {
        $article = makeEnglishArticle();

        expect($article->source_locale)->toBe('en')
            ->and($article->authoritativeTranslation()->locale)->toBe('en');
    });

    it('changes to a language the article has a version in and rewrites the mirror', function () {
        $article = makeEnglishArticle();

        $article->translations()->create([
            'locale' => 'de',
            'title' => 'Passwort zurücksetzen',
            'slug' => 'passwort-zuruecksetzen',
            'content' => 'Deutscher Inhalt.',
        ]);

        $article->changeSourceLocale('de');

        expect($article->fresh())
            ->source_locale->toBe('de')
            ->title->toBe('Passwort zurücksetzen')
            ->slug->toBe('passwort-zuruecksetzen');

        expect($article->fresh()->translations)->toHaveCount(2);
    });

    it('rejects changing to a language the article has no version in', function () {
        $article = makeEnglishArticle();

        expect(fn () => $article->changeSourceLocale('de'))->toThrow(InvalidArgumentException::class);
    });

    it('rejects deleting the authoritative version', function () {
        $article = makeEnglishArticle();

        expect(fn () => $article->authoritativeTranslation()->delete())
            ->toThrow(RuntimeException::class);
    });

    it('keeps the promoted version readable exactly when the article is', function () {
        $article = makeEnglishArticle();
        $article->translations()->create([
            'locale' => 'de',
            'title' => 'Passwort zurücksetzen',
            'slug' => 'passwort-zuruecksetzen',
            'content' => 'Deutscher Inhalt.',
        ]);

        $this->service->publishArticle($article);
        $article->changeSourceLocale('de');

        expect($article->authoritativeTranslation()->status)->toBe(TranslationStatus::Published);
    });
});

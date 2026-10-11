<?php

use JeffersonGoncalves\KnowledgeBase\Enums\TranslationStatus;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;
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

function localizedArticle(array $overrides = []): Article
{
    return test()->service->createArticle(array_merge([
        'category_id' => test()->category->id,
        'title' => 'Password reset',
        'slug' => 'password-reset',
        'content' => 'How to reset your password.',
    ], $overrides), test()->user);
}

function addGermanVersion(Article $article, string $status = 'published', string $slug = 'passwort-zuruecksetzen'): void
{
    $article->translations()->create([
        'locale' => 'de',
        'title' => 'Passwort zurücksetzen',
        'slug' => $slug,
        'content' => 'So setzt du dein Passwort zurück.',
        'status' => $status === 'published' ? TranslationStatus::Published : TranslationStatus::Draft,
    ]);
}

describe('Publishing per language', function () {
    it('serves English and not the German draft next to published English', function () {
        $article = localizedArticle();
        $this->service->publishArticle($article);
        addGermanVersion($article, 'draft');

        $english = $this->resolver->resolveArticle($article, 'en');
        $german = $this->resolver->resolveArticle($article, 'de');

        expect($english?->locale)->toBe('en')
            ->and($german?->locale)->toBe('en')
            ->and($german?->translation->getAttribute('title'))->toBe('Password reset');
    });

    it('serves the German version once it is published', function () {
        $article = localizedArticle();
        $this->service->publishArticle($article);
        addGermanVersion($article, 'published');

        $german = $this->resolver->resolveArticle($article, 'de');

        expect($german?->locale)->toBe('de')
            ->and($german?->translation->getAttribute('title'))->toBe('Passwort zurücksetzen')
            ->and($german?->slug)->toBe('passwort-zuruecksetzen');
    });

    it('shows no version at all when the article is archived', function () {
        $article = localizedArticle();
        $this->service->publishArticle($article);
        addGermanVersion($article, 'published');
        $this->service->archiveArticle($article);

        expect($this->resolver->resolveArticle($article->fresh(), 'en'))->toBeNull()
            ->and($this->resolver->resolveArticle($article->fresh(), 'de'))->toBeNull();
    });

    it('publishes the authoritative version when the article is published', function () {
        $article = localizedArticle();

        expect($article->authoritativeTranslation()->status)->toBe(TranslationStatus::Draft);

        $this->service->publishArticle($article);

        expect($article->fresh()->authoritativeTranslation()->status)->toBe(TranslationStatus::Published);
    });
});

describe('Missing-language behaviour', function () {
    it('falls back to the authoritative version and reports English', function () {
        $article = localizedArticle();
        $this->service->publishArticle($article);

        $resolved = $this->resolver->resolveArticle($article, 'de');

        expect($resolved?->locale)->toBe('en')
            ->and($resolved?->slug)->toBe('password-reset')
            ->and($resolved?->translation->getAttribute('title'))->toBe('Password reset');
    });

    it('hides the article when the requested language is missing', function () {
        config(['knowledge-base.missing_translation' => 'hide']);

        $article = localizedArticle();
        $this->service->publishArticle($article);

        expect($this->resolver->resolveArticle($article, 'de'))->toBeNull()
            ->and($this->resolver->resolveArticle($article, 'en')?->locale)->toBe('en');
    });

    it('serves the requested language when it is available', function () {
        $article = localizedArticle();
        $this->service->publishArticle($article);
        addGermanVersion($article, 'published');

        $resolved = $this->resolver->resolveArticle($article, 'de');

        expect($resolved?->locale)->toBe('de')
            ->and($resolved?->translation->getAttribute('slug'))->toBe('passwort-zuruecksetzen');
    });

    it('returns the default-language category when German is missing', function () {
        $category = Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);

        $resolved = $this->resolver->resolveCategory($category, 'de');

        expect($resolved?->locale)->toBe('en')
            ->and($resolved?->translation->getAttribute('name'))->toBe('Billing')
            ->and($resolved?->slug)->toBe('billing');
    });

    it('returns the German category when it exists', function () {
        $category = Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);
        $category->translations()->create([
            'locale' => 'de',
            'name' => 'Abrechnung',
            'slug' => 'abrechnung',
        ]);

        $resolved = $this->resolver->resolveCategory($category->fresh(), 'de');

        expect($resolved?->locale)->toBe('de')
            ->and($resolved?->translation->getAttribute('name'))->toBe('Abrechnung');
    });

    it('hides a category without a version when hidden behaviour is configured', function () {
        config(['knowledge-base.missing_translation' => 'hide']);

        $category = Category::factory()->create();

        expect($this->resolver->resolveCategory($category, 'de'))->toBeNull();
    });
});

describe('Language-aware slugs', function () {
    it('allows the same slug in two languages of one article', function () {
        $article = localizedArticle(['slug' => 'reset']);
        addGermanVersion($article, 'published', 'reset');
        $article = $article->fresh();
        $this->service->publishArticle($article);

        expect($this->resolver->resolveArticleBySlug('reset', 'en')?->model->getKey())->toBe($article->id)
            ->and($this->resolver->resolveArticleBySlug('reset', 'de')?->locale)->toBe('de');
    });

    it('rejects a slug that another article already uses', function () {
        localizedArticle(['slug' => 'reset']);
        $other = localizedArticle(['title' => 'Other', 'slug' => 'other-article']);

        expect(fn () => $other->translations()->create([
            'locale' => 'de',
            'title' => 'Anderer',
            'slug' => 'reset',
            'content' => 'Inhalt.',
        ]))->toThrow(InvalidArgumentException::class);
    });

    it('rejects a main-row slug that another article uses in another language', function () {
        $first = localizedArticle(['slug' => 'reset']);
        addGermanVersion($first, 'published', 'reset-de');
        $second = localizedArticle(['title' => 'Second', 'slug' => 'second-article']);

        expect(fn () => $second->update(['slug' => 'reset-de']))
            ->toThrow(InvalidArgumentException::class);

        // Rejected before anything is written: main row and its version still agree.
        expect($second->fresh()->slug)->toBe('second-article')
            ->and($second->fresh()->translation('en')->slug)->toBe('second-article');
    });

    it('creates no article when its slug belongs to another article', function () {
        $first = localizedArticle(['slug' => 'reset']);
        addGermanVersion($first, 'published', 'reset-de');

        // Written straight on the model, as the Filament admin does: no service
        // transaction to roll a half-saved row back.
        expect(fn () => Article::create([
            'category_id' => $this->category->id,
            'title' => 'Duplicate',
            'slug' => 'reset-de',
            'content' => 'Duplicate content.',
            'author_type' => $this->user->getMorphClass(),
            'author_id' => $this->user->id,
        ]))->toThrow(InvalidArgumentException::class);

        expect(Article::query()->where('title', 'Duplicate')->exists())->toBeFalse();
    });

    it('still saves other changes to an article without re-checking its own slug', function () {
        $article = localizedArticle(['slug' => 'reset']);
        addGermanVersion($article, 'published', 'reset-de');

        $article->fresh()->update(['title' => 'Password reset (updated)']);

        expect($article->fresh()->translation('en')->title)->toBe('Password reset (updated)');
    });

    it('never fails on a slug conflict when the authoritative language changes', function () {
        $article = localizedArticle(['slug' => 'reset']);
        addGermanVersion($article, 'published', 'passwort-zuruecksetzen');

        $article->changeSourceLocale('de');

        expect($article->fresh())->slug->toBe('passwort-zuruecksetzen');
    });

    it('returns the requested language and its slug when the given slug is from another language', function () {
        $article = localizedArticle(['slug' => 'reset']);
        addGermanVersion($article, 'published', 'passwort-zuruecksetzen');
        $this->service->publishArticle($article);

        $resolved = $this->resolver->resolveArticleBySlug('reset', 'de');

        expect($resolved?->locale)->toBe('de')
            ->and($resolved?->slug)->toBe('passwort-zuruecksetzen');
    });

    it('returns nothing for an unknown slug', function () {
        localizedArticle();

        expect($this->resolver->resolveArticleBySlug('does-not-exist', 'en'))->toBeNull();
    });

    it('resolves a category slug in another language', function () {
        $this->category->translations()->create([
            'locale' => 'de',
            'name' => 'Konto',
            'slug' => 'konto',
        ]);

        $resolved = $this->resolver->resolveCategoryBySlug('account', 'de');

        expect($resolved?->locale)->toBe('de')
            ->and($resolved?->slug)->toBe('konto');
    });
});

describe('Visibility applies to every language', function () {
    it('hides an internal article in German from public readers', function () {
        $article = localizedArticle(['visibility' => 'internal']);
        $this->service->publishArticle($article);
        addGermanVersion($article, 'published');

        expect($this->resolver->resolveArticle($article->fresh(), 'de'))->toBeNull()
            ->and($this->resolver->resolveArticle($article->fresh(), 'de', publicOnly: false)?->locale)->toBe('de');
    });

    it('hides a public article in an internal category in German', function () {
        $internalCategory = Category::factory()->create(['visibility' => 'internal']);
        $article = $this->service->createArticle([
            'category_id' => $internalCategory->id,
            'title' => 'Internal category article',
            'slug' => 'internal-category-article',
            'content' => 'Body.',
        ], $this->user);
        $this->service->publishArticle($article);
        addGermanVersion($article, 'published');

        expect($this->resolver->resolveArticle($article->fresh(), 'de'))->toBeNull();
    });
});

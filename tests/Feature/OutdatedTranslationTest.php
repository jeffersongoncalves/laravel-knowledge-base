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

function outdatedArticle(string $slug = 'password-reset'): Article
{
    $article = test()->service->createArticle([
        'category_id' => test()->category->id,
        'title' => 'Password reset',
        'slug' => $slug,
        'content' => 'How to apply.',
    ], test()->user);

    $article->translations()->create([
        'locale' => 'de',
        'title' => 'Passwort zurücksetzen',
        'slug' => $slug.'-de',
        'content' => 'So funktioniert es.',
        'status' => TranslationStatus::Published,
    ]);

    return $article;
}

describe('Outdated translations', function () {
    it('marks the other versions outdated when the authoritative version is saved with the option', function () {
        $article = outdatedArticle();

        $this->service->updateArticle($article->fresh(), ['title' => 'Password reset (revised)'], $this->user, 'Revision', true);

        $german = $article->fresh()->translation('de');

        expect($german->is_outdated)->toBeTrue()
            ->and($german->outdated_at)->not->toBeNull();
    });

    it('keeps an outdated version readable', function () {
        $article = outdatedArticle();
        $this->service->publishArticle($article);

        $this->service->updateArticle($article->fresh(), ['title' => 'Password reset (revised)'], $this->user, null, true);

        expect($this->resolver->resolveArticle($article->fresh(), 'de')?->locale)->toBe('de');
    });

    it('changes no other version when the option is not given', function () {
        $article = outdatedArticle();

        $this->service->updateArticle($article->fresh(), ['title' => 'Password reset (typo fix)'], $this->user);

        expect($article->fresh()->translation('de')->is_outdated)->toBeFalse();
    });

    it('clears the flag when the outdated version is edited', function () {
        $article = outdatedArticle();
        $this->service->updateArticle($article->fresh(), ['title' => 'Password reset (revised)'], $this->user, null, true);

        expect($article->fresh()->translation('de')->is_outdated)->toBeTrue();

        $this->service->updateArticleTranslation($article->fresh(), 'de', ['title' => 'Passwort zurücksetzen (aktualisiert)'], $this->user);

        expect($article->fresh()->translation('de')->is_outdated)->toBeFalse();
    });

    it('clears the flag when the version is confirmed without edits', function () {
        $article = outdatedArticle();
        $this->service->updateArticle($article->fresh(), ['title' => 'Password reset (revised)'], $this->user, null, true);

        $article->fresh()->translation('de')->markUpToDate();

        expect($article->fresh()->translation('de')->is_outdated)->toBeFalse();
    });

    it('lists exactly the outdated versions for a language', function () {
        $flagged = outdatedArticle('flagged-article');
        $untouched = outdatedArticle('untouched-article');

        $this->service->updateArticle($flagged->fresh(), ['title' => 'Flagged (revised)'], $this->user, null, true);

        $outdated = $this->service->outdatedTranslations('de');

        expect($outdated)->toHaveCount(1)
            ->and($outdated->first()->article_id)->toBe($flagged->id)
            ->and($outdated->first()->article_id)->not->toBe($untouched->id);
    });
});

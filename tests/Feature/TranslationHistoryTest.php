<?php

use JeffersonGoncalves\KnowledgeBase\Enums\TranslationStatus;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'knowledge-base.locales' => ['en', 'de'],
        'knowledge-base.default_locale' => 'en',
    ]);

    User::createTable();
    $this->service = app(KnowledgeBaseService::class);
    $this->user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $this->category = Category::factory()->create();
});

function historyArticle(): Article
{
    $article = test()->service->createArticle([
        'category_id' => test()->category->id,
        'title' => 'Password reset',
        'slug' => 'password-reset',
        'content' => 'How to apply.',
    ], test()->user);

    $article->translations()->create([
        'locale' => 'de',
        'title' => 'Passwort zurücksetzen',
        'slug' => 'passwort-zuruecksetzen',
        'content' => 'So funktioniert es.',
        'status' => TranslationStatus::Published,
    ]);

    return $article;
}

describe('Version history per language', function () {
    it('tags the initial history entry with the authoritative language', function () {
        $article = historyArticle();

        expect($article->versions)->toHaveCount(1)
            ->and($article->versions->first()->locale)->toBe('en');
    });

    it('records an entry for the edited language only', function () {
        $article = historyArticle();

        $this->service->updateArticleTranslation($article, 'de', ['title' => 'Passwort zurücksetzen (neu)'], $this->user, 'German edit');

        $article = $article->fresh();
        $german = $article->versions->where('locale', 'de');
        $english = $article->versions->where('locale', 'en');

        expect($german)->toHaveCount(1)
            ->and($german->first()->title)->toBe('Passwort zurücksetzen (neu)')
            ->and($german->first()->change_notes)->toBe('German edit')
            ->and($english)->toHaveCount(1)
            ->and($english->first()->title)->toBe('Password reset')
            ->and($article->versions)->toHaveCount(2);
    });

    it('tags the authoritative history entry when the main row is edited', function () {
        $article = historyArticle();

        $this->service->updateArticle($article, ['title' => 'Password reset (revised)'], $this->user);

        $latest = $article->fresh()->versions->sortBy('version_number')->last();

        expect($latest->locale)->toBe('en')
            ->and($latest->title)->toBe('Password reset (revised)');
    });

    it('keeps one global version sequence across languages', function () {
        $article = historyArticle();

        $this->service->updateArticleTranslation($article, 'de', ['title' => 'Deutsch'], $this->user);
        $this->service->updateArticle($article->fresh(), ['title' => 'English'], $this->user);

        $numbers = $article->fresh()->versions->pluck('version_number')->sort()->values()->all();

        expect($numbers)->toBe([1, 2, 3]);
    });
});

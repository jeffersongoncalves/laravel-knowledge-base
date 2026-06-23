<?php

use JeffersonGoncalves\KnowledgeBase\Enums\ArticleStatus;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;
use JeffersonGoncalves\KnowledgeBase\Events\ArticleCreated;
use JeffersonGoncalves\KnowledgeBase\Events\ArticleFeedbackReceived;
use JeffersonGoncalves\KnowledgeBase\Events\ArticlePublished;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    User::createTable();
    $this->service = app(KnowledgeBaseService::class);
    $this->user = User::create(['name' => 'John', 'email' => 'john@example.com']);
    $this->category = Category::factory()->create();
});

describe('Article Management', function () {
    it('creates an article with initial version', function () {
        Event::fake([ArticleCreated::class]);

        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Getting Started Guide',
            'slug' => 'getting-started-guide',
            'content' => 'This is the content of the article.',
            'excerpt' => 'A brief guide to get started.',
        ], $this->user);

        expect($article)
            ->title->toBe('Getting Started Guide')
            ->status->toBe(ArticleStatus::Draft)
            ->current_version->toBe(1);

        expect($article->versions)->toHaveCount(1);
        expect($article->versions->first()->version_number)->toBe(1);

        Event::assertDispatched(ArticleCreated::class);
    });

    it('creates article without version when versioning disabled', function () {
        config(['knowledge-base.versioning_enabled' => false]);

        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'No Version Article',
            'slug' => 'no-version-article',
            'content' => 'Content without versioning.',
        ], $this->user);

        expect($article->versions)->toHaveCount(0);

        config(['knowledge-base.versioning_enabled' => true]);
    });

    it('updates an article and creates new version', function () {
        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Original Title',
            'slug' => 'original-title',
            'content' => 'Original content.',
        ], $this->user);

        $updated = $this->service->updateArticle($article->fresh(), [
            'title' => 'Updated Title',
            'content' => 'Updated content.',
        ], $this->user, 'Fixed typos');

        expect($updated)
            ->title->toBe('Updated Title')
            ->current_version->toBe(2);

        expect($updated->versions)->toHaveCount(2);
    });

    it('publishes an article', function () {
        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Draft Article',
            'slug' => 'draft-article',
            'content' => 'Content to be published.',
        ], $this->user);

        Event::fake([ArticlePublished::class]);

        $published = $this->service->publishArticle($article);

        expect($published)
            ->status->toBe(ArticleStatus::Published)
            ->published_at->not->toBeNull();

        Event::assertDispatched(ArticlePublished::class);
    });

    it('archives an article', function () {
        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'To Archive',
            'slug' => 'to-archive',
            'content' => 'Content to be archived.',
        ], $this->user);

        $archived = $this->service->archiveArticle($article);

        expect($archived->status)->toBe(ArticleStatus::Archived);
    });

    it('deletes an article', function () {
        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'To Delete',
            'slug' => 'to-delete',
            'content' => 'Content to be deleted.',
        ], $this->user);

        $result = $this->service->deleteArticle($article);

        expect($result)->toBeTrue();
        expect(Article::count())->toBe(0);
        expect(Article::withTrashed()->count())->toBe(1);
    });
});

describe('Category Management', function () {
    it('creates a category with auto-slug', function () {
        $category = $this->service->createCategory([
            'name' => 'Frequently Asked Questions',
        ]);

        expect($category)
            ->name->toBe('Frequently Asked Questions')
            ->slug->toBe('frequently-asked-questions');
    });

    it('creates a category with custom slug', function () {
        $category = $this->service->createCategory([
            'name' => 'FAQ',
            'slug' => 'faq-section',
        ]);

        expect($category->slug)->toBe('faq-section');
    });

    it('updates a category', function () {
        $updated = $this->service->updateCategory($this->category, [
            'name' => 'Updated Name',
        ]);

        expect($updated->name)->toBe('Updated Name');
    });

    it('deletes a category', function () {
        $category = Category::factory()->create();
        $result = $this->service->deleteCategory($category);

        expect($result)->toBeTrue();
        expect(Category::withTrashed()->where('id', $category->id)->count())->toBe(1);
    });
});

describe('Feedback', function () {
    it('adds helpful feedback', function () {
        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Feedback Article',
            'slug' => 'feedback-article',
            'content' => 'Content for feedback.',
        ], $this->user);

        Event::fake([ArticleFeedbackReceived::class]);

        $feedback = $this->service->addFeedback($article, true, $this->user, 'Very helpful!', '127.0.0.1');

        expect($feedback)
            ->is_helpful->toBeTrue()
            ->comment->toBe('Very helpful!');

        expect($article->fresh()->helpful_count)->toBe(1);

        Event::assertDispatched(ArticleFeedbackReceived::class);
    });

    it('adds not helpful feedback', function () {
        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Not Helpful Article',
            'slug' => 'not-helpful-article',
            'content' => 'Content.',
        ], $this->user);

        $this->service->addFeedback($article, false, null, 'Needs improvement');

        expect($article->fresh()->not_helpful_count)->toBe(1);
    });

    it('allows anonymous feedback', function () {
        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Anonymous Feedback',
            'slug' => 'anonymous-feedback',
            'content' => 'Content.',
        ], $this->user);

        $feedback = $this->service->addFeedback($article, true);

        expect($feedback->user_type)->toBeNull();
        expect($feedback->user_id)->toBeNull();
    });
});

describe('Search', function () {
    it('searches published articles by title', function () {
        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Laravel Installation Guide',
            'slug' => 'laravel-installation-guide',
            'content' => 'How to install Laravel.',
        ], $this->user);

        $this->service->publishArticle($article);

        $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Draft Article',
            'slug' => 'draft-article',
            'content' => 'This is a draft about Laravel.',
        ], $this->user);

        $results = $this->service->search('Laravel');

        expect($results)->toHaveCount(1);
        expect($results->first()->title)->toBe('Laravel Installation Guide');
    });

    it('searches with category filter', function () {
        $otherCategory = Category::factory()->create();

        $article1 = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Article in Category 1',
            'slug' => 'article-cat-1',
            'content' => 'Searchable content.',
        ], $this->user);
        $this->service->publishArticle($article1);

        $article2 = $this->service->createArticle([
            'category_id' => $otherCategory->id,
            'title' => 'Article in Category 2',
            'slug' => 'article-cat-2',
            'content' => 'Searchable content.',
        ], $this->user);
        $this->service->publishArticle($article2);

        $results = $this->service->search('Searchable', [
            'category_id' => $this->category->id,
        ]);

        expect($results)->toHaveCount(1);
    });

    it('matches against article content', function () {
        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Unrelated Title',
            'slug' => 'unrelated-title',
            'content' => 'A deep dive into PHPStan static analysis.',
        ], $this->user);
        $this->service->publishArticle($article);

        $results = $this->service->search('PHPStan');

        expect($results)->toHaveCount(1);
        expect($results->first()->slug)->toBe('unrelated-title');
    });

    it('excludes internal articles for anonymous search by default', function () {
        $public = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Public Secret Topic',
            'slug' => 'public-secret-topic',
            'content' => 'Secret content for everyone.',
            'visibility' => ArticleVisibility::Public,
        ], $this->user);
        $this->service->publishArticle($public);

        $internal = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Internal Secret Topic',
            'slug' => 'internal-secret-topic',
            'content' => 'Secret content for staff only.',
            'visibility' => ArticleVisibility::Internal,
        ], $this->user);
        $this->service->publishArticle($internal);

        $results = $this->service->search('Secret');

        expect($results)->toHaveCount(1);
        expect($results->first()->visibility)->toBe(ArticleVisibility::Public);
    });

    it('includes internal articles when explicitly opted in', function () {
        $public = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Public Secret Topic',
            'slug' => 'public-secret-topic',
            'content' => 'Secret content for everyone.',
            'visibility' => ArticleVisibility::Public,
        ], $this->user);
        $this->service->publishArticle($public);

        $internal = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Internal Secret Topic',
            'slug' => 'internal-secret-topic',
            'content' => 'Secret content for staff only.',
            'visibility' => ArticleVisibility::Internal,
        ], $this->user);
        $this->service->publishArticle($internal);

        $all = $this->service->search('Secret', ['include_internal' => true]);
        expect($all)->toHaveCount(2);

        $onlyInternal = $this->service->search('Secret', ['visibility' => ArticleVisibility::Internal]);
        expect($onlyInternal)->toHaveCount(1);
        expect($onlyInternal->first()->visibility)->toBe(ArticleVisibility::Internal);
    });

    it('respects the result limit', function () {
        foreach (range(1, 5) as $i) {
            $article = $this->service->createArticle([
                'category_id' => $this->category->id,
                'title' => "Limited Article {$i}",
                'slug' => "limited-article-{$i}",
                'content' => 'Limited searchable content.',
            ], $this->user);
            $this->service->publishArticle($article);
        }

        $results = $this->service->search('Limited', ['limit' => 2]);

        expect($results)->toHaveCount(2);
    });

    it('escapes wildcard characters in the query', function () {
        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Plain title without wildcards',
            'slug' => 'plain-title',
            'content' => 'Just regular content.',
        ], $this->user);
        $this->service->publishArticle($article);

        // A bare "%" would match everything if not escaped.
        $results = $this->service->search('%');

        expect($results)->toHaveCount(0);
    });
});

describe('Configuration behaviour', function () {
    it('auto-generates a unique slug when none is provided', function () {
        $first = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Same Title',
            'content' => 'First content.',
        ], $this->user);

        $second = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Same Title',
            'content' => 'Second content.',
        ], $this->user);

        expect($first->slug)->toBe('same-title');
        expect($second->slug)->toBe('same-title-1');
    });

    it('applies the default visibility from config', function () {
        config(['knowledge-base.default_visibility' => 'internal']);

        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'Defaults To Internal',
            'content' => 'Content.',
        ], $this->user);

        expect($article->visibility)->toBe(ArticleVisibility::Internal);

        config(['knowledge-base.default_visibility' => 'public']);
    });

    it('throws when feedback is disabled', function () {
        config(['knowledge-base.feedback_enabled' => false]);

        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'No Feedback',
            'content' => 'Content.',
        ], $this->user);

        expect(fn () => $this->service->addFeedback($article, true))
            ->toThrow(RuntimeException::class);

        config(['knowledge-base.feedback_enabled' => true]);
    });

    it('does not track views when disabled', function () {
        config(['knowledge-base.track_views' => false]);

        $article = $this->service->createArticle([
            'category_id' => $this->category->id,
            'title' => 'No View Tracking',
            'content' => 'Content.',
        ], $this->user);

        $article->incrementViewCount();

        expect($article->fresh()->view_count)->toBe(0);

        config(['knowledge-base.track_views' => true]);
    });

    it('auto-generates a unique slug for categories', function () {
        $first = $this->service->createCategory(['name' => 'Duplicate Category']);
        $second = $this->service->createCategory(['name' => 'Duplicate Category']);

        expect($first->slug)->toBe('duplicate-category');
        expect($second->slug)->toBe('duplicate-category-1');
    });
});

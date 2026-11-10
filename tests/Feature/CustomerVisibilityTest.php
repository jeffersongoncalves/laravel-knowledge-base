<?php

use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Models\Collection;
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;
use JeffersonGoncalves\KnowledgeBase\Support\HelpCenter;
use JeffersonGoncalves\KnowledgeBase\Support\Visibility;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'knowledge-base.locales' => ['en'],
        'knowledge-base.default_locale' => 'en',
        'knowledge-base.missing_translation' => 'fallback',
    ]);

    User::createTable();
    $this->user = User::create(['name' => 'Editor', 'email' => 'editor@example.com']);
    $this->helpCenter = app(HelpCenter::class);

    $this->public = Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);
    $this->members = Category::factory()->create(['name' => 'Paid features', 'slug' => 'paid-features', 'visibility' => 'customers']);
    $this->internal = Category::factory()->create(['name' => 'Procedures', 'slug' => 'procedures', 'visibility' => 'internal']);
});

function cvArticle(array $overrides = []): Article
{
    return Article::factory()->published()->state([
        'author_type' => test()->user->getMorphClass(),
        'author_id' => test()->user->id,
        'category_id' => test()->public->id,
    ])->create($overrides);
}

function cvIds(array $resolved): array
{
    return array_map(fn ($item) => $item->model->getKey(), $resolved);
}

describe('Visibility levels', function () {
    it('lets each audience read its own level and the ones below', function () {
        expect(Visibility::levels(ArticleVisibility::Public))->toBe(['public'])
            ->and(Visibility::levels(ArticleVisibility::Customers))->toBe(['public', 'customers'])
            ->and(Visibility::levels(ArticleVisibility::Internal))->toBe(['public', 'customers', 'internal']);
    });

    it('keeps a customers article off the public site and shows it to customers', function () {
        $article = cvArticle(['slug' => 'export', 'visibility' => 'customers']);

        expect(Article::query()->publiclyVisible()->pluck('id')->all())->not->toContain($article->id)
            ->and(Visibility::applyCustomer(Article::query())->pluck('id')->all())->toContain($article->id)
            ->and(Visibility::articleIsPubliclyVisible($article))->toBeFalse()
            ->and(Visibility::articleIsVisibleTo($article, ArticleVisibility::Customers))->toBeTrue();
    });

    it('never makes an article more visible than its category', function () {
        $inMembers = cvArticle(['slug' => 'public-in-members', 'category_id' => $this->members->id]);
        $child = Category::factory()->withParent($this->members)->create();
        $inChild = cvArticle(['slug' => 'public-in-child', 'category_id' => $child->id]);

        expect(Visibility::articleIsPubliclyVisible($inMembers))->toBeFalse()
            ->and(Visibility::articleIsPubliclyVisible($inChild))->toBeFalse()
            ->and(Visibility::articleIsVisibleTo($inMembers, ArticleVisibility::Customers))->toBeTrue()
            ->and(Visibility::articleIsVisibleTo($inChild, ArticleVisibility::Customers))->toBeTrue();
    });

    it('keeps internal articles and categories from customers', function () {
        $internal = cvArticle(['slug' => 'internal', 'visibility' => 'internal']);
        $inInternal = cvArticle(['slug' => 'in-internal', 'category_id' => $this->internal->id]);

        expect(Visibility::articleIsVisibleTo($internal, ArticleVisibility::Customers))->toBeFalse()
            ->and(Visibility::articleIsVisibleTo($inInternal, ArticleVisibility::Customers))->toBeFalse()
            ->and(Visibility::categoryIdsFor(ArticleVisibility::Customers))->not->toContain($this->internal->id);
    });

    it('still counts a customers article as staff-only next to public ones', function () {
        $article = cvArticle(['slug' => 'export', 'visibility' => 'customers']);

        expect(Article::query()->staffOnly()->pluck('id')->all())->toContain($article->id);
    });
});

describe('Help Center for customers', function () {
    it('changes nothing for callers that do not pass an audience', function () {
        cvArticle(['slug' => 'export', 'visibility' => 'customers']);

        expect($this->helpCenter->article('export', 'en'))->toBeNull()
            ->and($this->helpCenter->category('paid-features', 'en'))->toBeNull()
            ->and(array_map(fn ($node) => $node->slug, $this->helpCenter->categories('en')))->toBe(['billing']);
    });

    it('serves customers articles and categories to the customers audience', function () {
        $public = cvArticle(['slug' => 'invoices']);
        $members = cvArticle(['slug' => 'export', 'visibility' => 'customers']);
        cvArticle(['slug' => 'api', 'category_id' => $this->members->id]);
        cvArticle(['slug' => 'internal', 'visibility' => 'internal']);

        $customers = ArticleVisibility::Customers;
        $nodes = $this->helpCenter->categories('en', $customers);
        $billing = $this->helpCenter->category('billing', 'en', $customers);

        expect(array_map(fn ($node) => [$node->slug, $node->articlesCount], $nodes))
            ->toBe([['billing', 2], ['paid-features', 1]])
            ->and(cvIds($billing->articles))->toEqualCanonicalizing([$public->id, $members->id])
            ->and($this->helpCenter->article('export', 'en', $customers)?->article->getKey())->toBe($members->id)
            ->and(cvIds($this->helpCenter->article('api', 'en', $customers)->breadcrumb))->toBe([$this->members->id])
            ->and($this->helpCenter->article('internal', 'en', $customers))->toBeNull();
    });

    it('lists customers related articles only for the customers audience', function () {
        $article = cvArticle(['slug' => 'invoices']);
        $related = cvArticle(['slug' => 'export', 'visibility' => 'customers']);
        $article->relatedArticles()->attach($related->id, ['sort_order' => 1]);

        expect($this->helpCenter->article('invoices', 'en')->related)->toBe([])
            ->and(cvIds($this->helpCenter->article('invoices', 'en', ArticleVisibility::Customers)->related))->toBe([$related->id]);
    });

    it('lists customers articles of a collection only for the customers audience', function () {
        $public = cvArticle(['slug' => 'invoices']);
        $members = cvArticle(['slug' => 'export', 'visibility' => 'customers']);
        $collection = Collection::factory()->create(['name' => 'Start here', 'slug' => 'start-here']);
        $collection->addArticle($public);
        $collection->addArticle($members);

        expect(cvIds($this->helpCenter->collection('start-here', 'en')->articles))->toBe([$public->id])
            ->and(cvIds($this->helpCenter->collection('start-here', 'en', ArticleVisibility::Customers)->articles))
            ->toBe([$public->id, $members->id]);
    });

    it('searches what the audience may read', function () {
        cvArticle(['slug' => 'invoices', 'title' => 'Reset invoices']);
        $members = cvArticle(['slug' => 'export', 'title' => 'Reset exports', 'visibility' => 'customers']);
        cvArticle(['slug' => 'internal', 'title' => 'Reset procedures', 'visibility' => 'internal']);

        $public = $this->helpCenter->search('Reset', 'en');
        $customers = $this->helpCenter->search('Reset', 'en', audience: ArticleVisibility::Customers);

        expect($public->pluck('slug')->all())->toBe(['invoices'])
            ->and($customers->pluck('slug')->sort()->values()->all())->toBe(['export', 'invoices'])
            ->and(app(KnowledgeBaseService::class)->search('Reset', ['visibility' => 'customers'])->pluck('id')->all())
            ->toContain($members->id);
    });
});

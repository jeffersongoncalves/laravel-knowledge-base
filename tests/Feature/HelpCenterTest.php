<?php

use JeffersonGoncalves\KnowledgeBase\Enums\TranslationStatus;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Models\Collection;
use JeffersonGoncalves\KnowledgeBase\Support\HelpCenter;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'knowledge-base.locales' => ['en', 'de'],
        'knowledge-base.default_locale' => 'en',
        'knowledge-base.missing_translation' => 'fallback',
    ]);

    User::createTable();
    $this->user = User::create(['name' => 'Editor', 'email' => 'editor@example.com']);
    $this->helpCenter = app(HelpCenter::class);

    $this->public = Category::factory()->create(['name' => 'Billing', 'slug' => 'billing']);
    $this->internal = Category::factory()->create(['name' => 'Internal procedures', 'slug' => 'internal-procedures', 'visibility' => 'internal']);
});

function hcArticle(array $overrides = []): Article
{
    return Article::factory()->published()->state([
        'author_type' => test()->user->getMorphClass(),
        'author_id' => test()->user->id,
        'category_id' => test()->public->id,
    ])->create($overrides);
}

function hcGermanVersion(Article $article, string $status = 'published', string $slug = 'deutsch'): void
{
    $article->translations()->create([
        'locale' => 'de',
        'title' => 'Deutsch',
        'slug' => $slug,
        'content' => 'Inhalt.',
        'status' => $status === 'published' ? TranslationStatus::Published : TranslationStatus::Draft,
    ]);
}

describe('Public only', function () {
    it('reports not found for an internal article by slug', function () {
        hcArticle(['slug' => 'internal-article', 'visibility' => 'internal']);

        expect($this->helpCenter->article('internal-article', 'en'))->toBeNull();
    });

    it('reports not found for a public article in an internal category', function () {
        hcArticle(['slug' => 'in-internal', 'category_id' => $this->internal->id]);

        expect($this->helpCenter->article('in-internal', 'en'))->toBeNull();
    });

    it('reports not found for an internal category by slug', function () {
        expect($this->helpCenter->category('internal-procedures', 'en'))->toBeNull();
    });
});

describe('Category tree', function () {
    it('returns the public categories in German with their public article counts', function () {
        $this->public->translations()->create(['locale' => 'de', 'name' => 'Abrechnung', 'slug' => 'abrechnung']);
        hcArticle(['slug' => 'public-one']);
        hcArticle(['slug' => 'public-two']);
        hcArticle(['slug' => 'internal-in-public', 'visibility' => 'internal']);
        hcArticle(['slug' => 'in-internal', 'category_id' => $this->internal->id]);

        $nodes = $this->helpCenter->categories('de');

        expect($nodes)->toHaveCount(1)
            ->and($nodes[0]->name)->toBe('Abrechnung')
            ->and($nodes[0]->slug)->toBe('abrechnung')
            ->and($nodes[0]->articlesCount)->toBe(2);
    });

    it('falls back to the default language for a category without a version', function () {
        $nodes = $this->helpCenter->categories('de');

        expect($nodes[0]->name)->toBe('Billing')
            ->and($nodes[0]->locale)->toBe('en');
    });

    it('nests publicly visible child categories', function () {
        $child = Category::factory()->create([
            'name' => 'Customer questions',
            'slug' => 'customer-questions',
            'parent_id' => $this->public->id,
        ]);

        $nodes = $this->helpCenter->categories('en');

        expect($nodes[0]->category->getKey())->toBe($this->public->id)
            ->and($nodes[0]->children)->toHaveCount(1)
            ->and($nodes[0]->children[0]->category->getKey())->toBe($child->id);
    });
});

describe('Category page', function () {
    it('returns only the public articles of a category', function () {
        $visible = hcArticle(['slug' => 'visible']);
        hcArticle(['slug' => 'internal-article', 'visibility' => 'internal']);

        $page = $this->helpCenter->category('billing', 'en');

        expect($page)->not->toBeNull()
            ->and($page->articles)->toHaveCount(1)
            ->and($page->articles[0]->model->getKey())->toBe($visible->id);
    });

    it('redirects a category slug from another language', function () {
        $this->public->translations()->create(['locale' => 'de', 'name' => 'Abrechnung', 'slug' => 'abrechnung']);

        $page = $this->helpCenter->category('billing', 'de');

        expect($page->locale)->toBe('de')
            ->and($page->slug)->toBe('abrechnung')
            ->and($page->redirectSlug)->toBe('abrechnung');
    });
});

describe('Article page', function () {
    it('returns the requested language with no fallback or redirect', function () {
        $article = hcArticle(['slug' => 'english-slug']);
        hcGermanVersion($article, 'published', 'deutscher-slug');

        $page = $this->helpCenter->article('deutscher-slug', 'de');

        expect($page)->not->toBeNull()
            ->and($page->locale)->toBe('de')
            ->and($page->slug)->toBe('deutscher-slug')
            ->and($page->isFallback)->toBeFalse()
            ->and($page->redirectSlug)->toBeNull();
    });

    it('reports a redirect when an article is asked for by another language slug', function () {
        $article = hcArticle(['slug' => 'english-slug']);
        hcGermanVersion($article, 'published', 'deutscher-slug');

        $page = $this->helpCenter->article('english-slug', 'de');

        expect($page->locale)->toBe('de')
            ->and($page->slug)->toBe('deutscher-slug')
            ->and($page->redirectSlug)->toBe('deutscher-slug');
    });

    it('falls back to the authoritative version and marks it', function () {
        hcArticle(['slug' => 'english-only']);

        $page = $this->helpCenter->article('english-only', 'de');

        expect($page->locale)->toBe('en')
            ->and($page->isFallback)->toBeTrue()
            ->and($page->slug)->toBe('english-only');
    });

    it('lists only the languages an article can actually be read in', function () {
        $article = hcArticle(['slug' => 'english-slug']);
        hcGermanVersion($article, 'draft', 'deutscher-slug');

        $page = $this->helpCenter->article('english-slug', 'en');

        expect($page->alternates)->toBe(['en' => 'english-slug']);
    });

    it('lists every readable language with its own slug', function () {
        $article = hcArticle(['slug' => 'english-slug']);
        hcGermanVersion($article, 'published', 'deutscher-slug');

        $page = $this->helpCenter->article('english-slug', 'en');

        expect($page->alternates)->toBe([
            'en' => 'english-slug',
            'de' => 'deutscher-slug',
        ]);
    });

    it('returns only the public related articles', function () {
        $article = hcArticle(['slug' => 'main']);
        $public = hcArticle(['slug' => 'related-public']);
        $internal = hcArticle(['slug' => 'related-internal', 'visibility' => 'internal']);
        $inInternalCategory = hcArticle(['slug' => 'related-in-internal', 'category_id' => $this->internal->id]);
        $article->relatedArticles()->attach([$public->id, $internal->id, $inInternalCategory->id]);

        $page = $this->helpCenter->article('main', 'en');

        expect($page->related)->toHaveCount(1)
            ->and($page->related[0]->model->getKey())->toBe($public->id);
    });

    it('returns the category breadcrumb, root first', function () {
        $child = Category::factory()->create([
            'name' => 'Customer questions',
            'slug' => 'customer-questions',
            'parent_id' => $this->public->id,
        ]);
        hcArticle(['slug' => 'nested', 'category_id' => $child->id]);

        $page = $this->helpCenter->article('nested', 'en');

        expect(array_map(fn ($item) => $item->model->getKey(), $page->breadcrumb))
            ->toBe([$this->public->id, $child->id]);
    });
});

describe('Collection page', function () {
    it('returns the collection in German with its public articles in order', function () {
        $collection = Collection::create(['name' => 'Frequently asked questions', 'slug' => 'frequently-asked-questions']);
        $collection->translations()->create(['locale' => 'de', 'name' => 'Häufige Fragen', 'slug' => 'haeufige-fragen']);

        $first = hcArticle(['slug' => 'first']);
        $hidden = hcArticle(['slug' => 'hidden', 'visibility' => 'internal']);
        $second = hcArticle(['slug' => 'second']);
        $collection->addArticle($first);
        $collection->addArticle($hidden);
        $collection->addArticle($second);

        $page = $this->helpCenter->collection('haeufige-fragen', 'de');

        expect($page)->not->toBeNull()
            ->and((string) $page->translation->getAttribute('name'))->toBe('Häufige Fragen')
            ->and($page->locale)->toBe('de')
            ->and(array_map(fn ($item) => $item->model->getKey(), $page->articles))->toBe([$first->id, $second->id]);
    });

    it('reports not found for an inactive collection', function () {
        Collection::factory()->inactive()->create(['slug' => 'inactive']);

        expect($this->helpCenter->collection('inactive', 'en'))->toBeNull();
    });
});

describe('Search', function () {
    it('returns only public articles with the language that was served', function () {
        $german = hcArticle([
            'slug' => 'german-article',
            'title' => 'Password reset',
            'content' => 'Applying for a password reset.',
        ]);
        $german->translations()->create([
            'locale' => 'de',
            'title' => 'Passwort anfordern',
            'slug' => 'passwort-anfordern',
            'content' => 'Alles über dein Konto.',
            'status' => TranslationStatus::Published,
        ]);
        hcArticle([
            'slug' => 'internal-reset',
            'title' => 'Eskalation an den Support',
            'content' => 'Interne Anleitung zum Konto.',
            'visibility' => 'internal',
        ]);

        $results = $this->helpCenter->search('Konto', 'de');

        expect($results->pluck('id')->all())->toBe([$german->id])
            ->and($results->first()->served_locale)->toBe('de');
    });
});

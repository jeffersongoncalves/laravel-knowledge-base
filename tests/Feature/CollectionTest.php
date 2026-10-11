<?php

use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Models\Collection;
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
    $this->user = User::create(['name' => 'Editor', 'email' => 'editor@example.com']);
    $this->service = app(KnowledgeBaseService::class);
    $this->resolver = app(TranslationResolver::class);
    $this->public = Category::factory()->create(['name' => 'Account', 'slug' => 'account']);
    $this->internal = Category::factory()->create(['name' => 'Internal', 'slug' => 'internal', 'visibility' => 'internal']);
});

function collectionArticle(array $overrides = []): Article
{
    return Article::factory()->published()->state([
        'author_type' => test()->user->getMorphClass(),
        'author_id' => test()->user->id,
        'category_id' => test()->public->id,
    ])->create($overrides);
}

describe('Collections', function () {
    it('serves an FAQ collection in two languages', function () {
        $collection = Collection::create([
            'name' => 'Frequently asked questions',
            'slug' => 'frequently-asked-questions',
        ]);

        $collection->translations()->create([
            'locale' => 'de',
            'name' => 'Häufige Fragen',
            'slug' => 'haeufige-fragen',
        ]);

        $german = $this->resolver->resolveCollection($collection->fresh(), 'de');
        $english = $this->resolver->resolveCollection($collection->fresh(), 'en');

        expect($english?->translation->getAttribute('name'))->toBe('Frequently asked questions')
            ->and($german?->translation->getAttribute('name'))->toBe('Häufige Fragen')
            ->and($german?->slug)->toBe('haeufige-fragen');
    });

    it('always has a version in the default language', function () {
        $collection = Collection::create([
            'name' => 'Getting started',
            'slug' => 'getting-started',
        ]);

        expect($collection->translation('en'))->not->toBeNull()
            ->and($collection->translation('en')->name)->toBe('Getting started');
    });

    it('rejects a slug another collection already uses', function () {
        Collection::create(['name' => 'Frequently asked questions', 'slug' => 'faq']);
        $other = Collection::create(['name' => 'Getting started', 'slug' => 'getting-started']);

        expect(fn () => $other->translations()->create([
            'locale' => 'de',
            'name' => 'Aan de slag',
            'slug' => 'faq',
        ]))->toThrow(InvalidArgumentException::class);

        expect($other->fresh()->translation('de'))->toBeNull();
    });
});

describe('Ordered articles in a collection', function () {
    it('keeps the chosen order', function () {
        $a = collectionArticle(['title' => 'A', 'slug' => 'a']);
        $b = collectionArticle(['title' => 'B', 'slug' => 'b']);
        $c = collectionArticle(['title' => 'C', 'slug' => 'c']);
        $collection = Collection::create(['name' => 'FAQ', 'slug' => 'faq']);

        $collection->addArticle($c);
        $collection->addArticle($a);
        $collection->addArticle($b);

        expect($collection->articleIds())->toBe([$c->id, $a->id, $b->id])
            ->and($collection->articles()->pluck('title')->all())->toBe(['C', 'A', 'B']);
    });

    it('reorders the articles by the given sequence', function () {
        $a = collectionArticle(['slug' => 'a']);
        $b = collectionArticle(['slug' => 'b']);
        $c = collectionArticle(['slug' => 'c']);
        $collection = Collection::create(['name' => 'FAQ', 'slug' => 'faq']);
        $collection->addArticle($a);
        $collection->addArticle($b);
        $collection->addArticle($c);

        $collection->reorderArticles([$c, $a, $b]);

        expect($collection->articleIds())->toBe([$c->id, $a->id, $b->id]);
    });

    it('rejects the same article twice and keeps it once', function () {
        $article = collectionArticle(['slug' => 'a']);
        $collection = Collection::create(['name' => 'FAQ', 'slug' => 'faq']);
        $collection->addArticle($article);

        expect(fn () => $collection->addArticle($article))->toThrow(InvalidArgumentException::class)
            ->and($collection->articleIds())->toBe([$article->id]);
    });

    it('keeps each collection position when an article is in several collections', function () {
        $article = collectionArticle(['slug' => 'shared']);
        $other = collectionArticle(['slug' => 'other']);
        $first = Collection::create(['name' => 'FAQ', 'slug' => 'faq']);
        $second = Collection::create(['name' => 'Getting started', 'slug' => 'getting-started']);

        $first->addArticle($article);
        $first->addArticle($other);
        $second->addArticle($other);
        $second->addArticle($article);

        expect($first->articleIds())->toBe([$article->id, $other->id])
            ->and($second->articleIds())->toBe([$other->id, $article->id]);
    });

    it('leaves the articles untouched when they are removed or the collection is deleted', function () {
        $article = collectionArticle(['slug' => 'a']);
        $collection = Collection::create(['name' => 'FAQ', 'slug' => 'faq']);
        $collection->addArticle($article);

        $collection->removeArticle($article);

        expect($collection->articleIds())->toBe([])
            ->and(Article::find($article->id))->not->toBeNull();
    });
});

describe('Public view of a collection', function () {
    beforeEach(function () {
        $this->collection = Collection::create(['name' => 'FAQ', 'slug' => 'faq']);
    });

    it('shows a customer only the publicly visible articles', function () {
        $visible = collectionArticle(['slug' => 'visible']);
        $internal = collectionArticle(['slug' => 'internal-article', 'visibility' => 'internal']);
        $inInternalCategory = collectionArticle(['slug' => 'in-internal', 'category_id' => $this->internal->id]);

        $this->collection->addArticle($visible);
        $this->collection->addArticle($internal);
        $this->collection->addArticle($inInternalCategory);

        $ids = array_map(fn ($resolved) => $resolved->model->getKey(), $this->collection->publicArticles('en'));

        expect($ids)->toBe([$visible->id]);
    });

    it('drops an article from the public view once it becomes internal', function () {
        $article = collectionArticle(['slug' => 'becomes-internal']);
        $this->collection->addArticle($article);

        $article->update(['visibility' => 'internal']);

        expect($this->collection->publicArticles('en'))->toBe([])
            ->and($this->collection->articleIds())->toBe([$article->id]);
    });

    it('serves the authoritative language to a German reader under fallback', function () {
        $germanArticle = collectionArticle(['slug' => 'with-german']);
        $germanArticle->translations()->create([
            'locale' => 'de',
            'title' => 'Mit Deutsch',
            'slug' => 'mit-deutsch',
            'content' => 'Inhalt.',
            'status' => 'published',
        ]);
        $englishOnly = collectionArticle(['slug' => 'english-only']);

        $this->collection->addArticle($germanArticle);
        $this->collection->addArticle($englishOnly);

        $resolved = $this->collection->publicArticles('de');

        expect($resolved)->toHaveCount(2)
            ->and($resolved[0]->locale)->toBe('de')
            ->and($resolved[1]->locale)->toBe('en')
            ->and($resolved[1]->slug)->toBe('english-only');
    });

    it('leaves an article out for a German reader under hide, keeping the rest in order', function () {
        config(['knowledge-base.missing_translation' => 'hide']);

        $first = collectionArticle(['slug' => 'first']);
        $first->translations()->create([
            'locale' => 'de', 'title' => 'Eerste', 'slug' => 'eerste',
            'content' => 'Inhalt.', 'status' => 'published',
        ]);
        $englishOnly = collectionArticle(['slug' => 'english-only']);
        $last = collectionArticle(['slug' => 'last']);
        $last->translations()->create([
            'locale' => 'de', 'title' => 'Laatste', 'slug' => 'laatste',
            'content' => 'Inhalt.', 'status' => 'published',
        ]);

        $this->collection->addArticle($first);
        $this->collection->addArticle($englishOnly);
        $this->collection->addArticle($last);

        $ids = array_map(fn ($resolved) => $resolved->model->getKey(), $this->collection->publicArticles('de'));

        expect($ids)->toBe([$first->id, $last->id]);
    });

    it('reports not found for an inactive collection', function () {
        $inactive = Collection::factory()->inactive()->create(['slug' => 'inactive']);

        expect($this->resolver->resolveCollectionBySlug('inactive', 'en'))->toBeNull()
            ->and($this->resolver->resolveCollectionBySlug('faq', 'en'))->not->toBeNull();
    });
});

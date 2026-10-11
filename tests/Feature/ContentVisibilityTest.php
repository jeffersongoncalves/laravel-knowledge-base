<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;
use JeffersonGoncalves\KnowledgeBase\Support\Visibility;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

function kbArticle()
{
    return Article::factory()->state([
        'author_type' => test()->user->getMorphClass(),
        'author_id' => test()->user->id,
    ]);
}

function publicIds(): array
{
    return Article::query()->publiclyVisible()->pluck('id')->sort()->values()->all();
}

function staffOnlyIds(): array
{
    return Article::query()->staffOnly()->pluck('id')->sort()->values()->all();
}

beforeEach(function () {
    User::createTable();
    $this->user = User::create(['name' => 'Staff', 'email' => 'staff@example.com']);
    $this->public = Category::factory()->create(['name' => 'Public', 'slug' => 'public']);
    $this->internal = Category::factory()->create(['name' => 'Internal', 'slug' => 'internal', 'visibility' => 'internal']);
});

describe('Publicly visible category', function () {
    it('lists only active, public categories for customers', function () {
        $inactive = Category::factory()->inactive()->create();

        $ids = Category::query()->publiclyVisible()->pluck('id')->all();

        expect($ids)->toContain($this->public->id)
            ->not->toContain($this->internal->id)
            ->not->toContain($inactive->id);
    });

    it('hides a public child of an internal or inactive ancestor', function () {
        $underInternal = Category::factory()->withParent($this->internal)->create();
        $inactiveParent = Category::factory()->inactive()->create();
        $underInactive = Category::factory()->withParent($inactiveParent)->create();
        $underPublic = Category::factory()->withParent($this->public)->create();

        $ids = Category::query()->publiclyVisible()->pluck('id')->all();

        expect($ids)->toContain($underPublic->id)
            ->not->toContain($underInternal->id)
            ->not->toContain($underInactive->id);
    });

    it('resolves a three-level chain from the top', function () {
        $middle = Category::factory()->withParent($this->internal)->create();
        $leaf = Category::factory()->withParent($middle)->create();
        $publicMiddle = Category::factory()->withParent($this->public)->create();
        $publicLeaf = Category::factory()->withParent($publicMiddle)->create();

        expect(Visibility::publicCategoryIds())
            ->toContain($publicLeaf->id)
            ->not->toContain($leaf->id);
    });

    it('hides deleted categories and their children', function () {
        $parent = Category::factory()->create();
        $child = Category::factory()->withParent($parent)->create();
        $parent->delete();

        expect(Visibility::publicCategoryIds())
            ->not->toContain($parent->id)
            ->not->toContain($child->id);
    });

    it('hides categories in a parent cycle', function () {
        $a = Category::factory()->create();
        $b = Category::factory()->withParent($a)->create();
        $a->update(['parent_id' => $b->id]);

        expect(Visibility::publicCategoryIds())
            ->not->toContain($a->id)
            ->not->toContain($b->id);
    });

    it('hides a category whose parent no longer exists', function () {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->markTestSkipped('PostgreSQL cannot defer non-deferrable foreign keys.');
        }

        $orphan = Category::factory()->create();

        Schema::withoutForeignKeyConstraints(function () use ($orphan) {
            DB::table($orphan->getTable())->where('id', $orphan->id)->update(['parent_id' => 999999]);
        });

        expect(Visibility::publicCategoryIds())->not->toContain($orphan->id);
    });
});

describe('Publicly visible article', function () {
    it('shows a public article in a public category', function () {
        $article = kbArticle()->published()->create(['category_id' => $this->public->id]);

        expect(publicIds())->toBe([$article->id]);
    });

    it('hides an internal article, whatever its category', function () {
        kbArticle()->published()->internal()->create(['category_id' => $this->public->id]);

        expect(publicIds())->toBe([]);
    });

    it('hides a public article in an internal category', function () {
        kbArticle()->published()->create(['category_id' => $this->internal->id]);

        expect(publicIds())->toBe([]);
    });

    it('hides a public article under an internal ancestor', function () {
        $child = Category::factory()->withParent($this->internal)->create();
        $grandchild = Category::factory()->withParent($child)->create();
        kbArticle()->published()->create(['category_id' => $child->id]);
        kbArticle()->published()->create(['category_id' => $grandchild->id]);

        expect(publicIds())->toBe([]);
    });

    it('hides a public article in or under an inactive category', function () {
        $inactive = Category::factory()->inactive()->create();
        $child = Category::factory()->withParent($inactive)->create();
        kbArticle()->published()->create(['category_id' => $inactive->id]);
        kbArticle()->published()->create(['category_id' => $child->id]);

        expect(publicIds())->toBe([]);
    });

    it('hides a public article in a deleted category', function () {
        kbArticle()->published()->create(['category_id' => $this->public->id]);
        $this->public->delete();

        expect(publicIds())->toBe([]);
    });

    it('hides drafts and archived articles', function () {
        kbArticle()->create(['category_id' => $this->public->id]);
        kbArticle()->archived()->create(['category_id' => $this->public->id]);

        expect(publicIds())->toBe([]);
    });
});

describe('Public article query', function () {
    it('returns only publicly visible related articles', function () {
        $article = kbArticle()->published()->create(['category_id' => $this->public->id]);
        $visible = kbArticle()->published()->create(['category_id' => $this->public->id]);
        $internal = kbArticle()->published()->internal()->create(['category_id' => $this->public->id]);
        $inInternal = kbArticle()->published()->create(['category_id' => $this->internal->id]);
        $article->relatedArticles()->attach([$visible->id, $internal->id, $inInternal->id]);

        $related = $article->relatedArticles()->publiclyVisible()->pluck($article->qualifyColumn('id'))->all();

        expect($related)->toBe([$visible->id]);
    });

    it('finds no internal article by its slug', function () {
        kbArticle()->published()->internal()->create([
            'category_id' => $this->public->id,
            'slug' => 'staff-escalation',
        ]);

        expect(Article::query()->publiclyVisible()->where('slug', 'staff-escalation')->first())->toBeNull();
    });

    it('returns no articles when browsing an internal category', function () {
        kbArticle()->published()->count(2)->create(['category_id' => $this->internal->id]);

        expect($this->internal->articles()->publiclyVisible()->count())->toBe(0);
    });

    it('can be combined with a category filter', function () {
        $other = Category::factory()->create();
        $here = kbArticle()->published()->create(['category_id' => $this->public->id]);
        kbArticle()->published()->create(['category_id' => $other->id]);

        expect($this->public->articles()->publiclyVisible()->pluck('id')->all())->toBe([$here->id]);
    });
});

describe('Staff-only article query', function () {
    it('includes internal articles', function () {
        $article = kbArticle()->published()->internal()->create(['category_id' => $this->public->id]);

        expect(staffOnlyIds())->toBe([$article->id]);
    });

    it('includes public articles hidden by their category', function () {
        $inactive = Category::factory()->inactive()->create();
        $deleted = Category::factory()->create();
        $underInternal = Category::factory()->withParent($this->internal)->create();

        $ids = collect([
            kbArticle()->published()->create(['category_id' => $this->internal->id]),
            kbArticle()->published()->create(['category_id' => $inactive->id]),
            kbArticle()->published()->create(['category_id' => $deleted->id]),
            kbArticle()->published()->create(['category_id' => $underInternal->id]),
        ])->pluck('id')->sort()->values()->all();
        $deleted->delete();

        expect(staffOnlyIds())->toBe($ids);
    });

    it('excludes publicly visible articles', function () {
        kbArticle()->published()->create(['category_id' => $this->public->id]);

        expect(staffOnlyIds())->toBe([]);
    });

    it('never includes drafts or archived articles', function () {
        kbArticle()->internal()->create(['category_id' => $this->internal->id]);
        kbArticle()->archived()->internal()->create(['category_id' => $this->internal->id]);

        expect(staffOnlyIds())->toBe([]);
    });

    it('splits the published articles with the public query', function () {
        $inactive = Category::factory()->inactive()->create();

        foreach ([$this->public, $this->internal, $inactive] as $category) {
            kbArticle()->published()->create(['category_id' => $category->id]);
            kbArticle()->published()->internal()->create(['category_id' => $category->id]);
            kbArticle()->create(['category_id' => $category->id]);
        }

        $published = Article::query()->published()->pluck('id')->sort()->values()->all();

        expect(array_intersect(publicIds(), staffOnlyIds()))->toBe([])
            ->and(collect(publicIds())->merge(staffOnlyIds())->sort()->values()->all())->toBe($published);
    });
});

describe('Status-only published query', function () {
    it('still includes internal content for staff tools', function () {
        $internal = kbArticle()->published()->internal()->create(['category_id' => $this->public->id]);
        $inInternal = kbArticle()->published()->create(['category_id' => $this->internal->id]);

        expect(Article::query()->published()->pluck('id')->all())
            ->toContain($internal->id)
            ->toContain($inInternal->id);
    });

    it('treats an article for both audiences as public', function () {
        $both = kbArticle()->published()->create([
            'category_id' => $this->public->id,
            'visibility' => ArticleVisibility::Public,
        ]);

        expect(publicIds())->toContain($both->id)
            ->and(Article::query()->published()->pluck('id')->all())->toContain($both->id);
    });
});

describe('Search', function () {
    beforeEach(function () {
        $this->service = app(KnowledgeBaseService::class);

        $this->visible = kbArticle()->published()->create([
            'category_id' => $this->public->id,
            'title' => 'Password reset visible',
            'content' => 'Password reset for customers.',
        ]);
        $this->internalArticle = kbArticle()->published()->internal()->create([
            'category_id' => $this->public->id,
            'title' => 'Password reset escalation',
            'content' => 'Password reset for staff.',
        ]);
        $this->inInternalCategory = kbArticle()->published()->create([
            'category_id' => $this->internal->id,
            'title' => 'Password reset checklist',
            'content' => 'Password reset checklist.',
        ]);
        kbArticle()->create([
            'category_id' => $this->internal->id,
            'title' => 'Password reset draft',
            'content' => 'Password reset draft.',
        ]);
    });

    it('returns only publicly visible articles by default', function () {
        expect($this->service->search('Password')->pluck('id')->all())->toBe([$this->visible->id]);
    });

    it('gives the same results when asking for public explicitly', function () {
        $default = $this->service->search('Password')->pluck('id')->all();

        expect($this->service->search('Password', ['visibility' => 'public'])->pluck('id')->all())->toBe($default)
            ->and($this->service->search('Password', ['visibility' => ArticleVisibility::Public])->pluck('id')->all())->toBe($default);
    });

    it('returns every published article when internal content is included', function () {
        $ids = $this->service->search('Password', ['include_internal' => true])->pluck('id')->sort()->values()->all();

        expect($ids)->toBe([$this->visible->id, $this->internalArticle->id, $this->inInternalCategory->id]);
    });

    it('returns the staff-only articles when asking for internal', function () {
        $ids = $this->service->search('Password', ['visibility' => 'internal'])->pluck('id')->sort()->values()->all();

        expect($ids)->toBe([$this->internalArticle->id, $this->inInternalCategory->id]);
    });
});

<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\KnowledgeBase\Enums\ArticleVisibility;
use JeffersonGoncalves\KnowledgeBase\Enums\AssetKind;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Support\ArticleAssets;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'knowledge-base.locales' => ['en', 'de'],
        'knowledge-base.default_locale' => 'en',
    ]);

    Storage::fake('local');
    $this->freezeTime();

    User::createTable();
    $this->assets = app(ArticleAssets::class);
    $this->user = User::create(['name' => 'Staff', 'email' => 'staff@example.com']);
    $this->category = Category::factory()->create(['name' => 'Plant care', 'slug' => 'plant-care']);
});

function servedArticle(array $attributes = [], bool $published = true): Article
{
    $factory = Article::factory()->state([
        'author_type' => test()->user->getMorphClass(),
        'author_id' => test()->user->id,
        'category_id' => test()->category->id,
        ...$attributes,
    ]);

    /** @var Article $article */
    $article = ($published ? $factory->published() : $factory)->create();

    return $article;
}

function servedImage(Article $article)
{
    return test()->assets->store($article, UploadedFile::fake()->image('fern.jpg', 20, 10), AssetKind::Image);
}

/** The plain, unsigned URL of an asset, whatever its article's visibility. */
function plainUrl($asset): string
{
    return route(ArticleAssets::ROUTE, ['asset' => $asset->uuid, 'name' => basename($asset->path)]);
}

describe('Private by default', function () {
    it('stores new assets on the private local disk', function () {
        $asset = servedImage(servedArticle());

        expect($asset->disk)->toBe('local')
            ->and(Storage::disk('local')->exists($asset->path))->toBeTrue();
    });
});

describe('Public articles', function () {
    it('serves an image of a public article to anyone, cacheable', function () {
        $asset = servedImage(servedArticle());

        $url = $this->assets->url($asset);
        $response = $this->get($url);

        expect($url)->toBe(plainUrl($asset))
            ->and($url)->not->toContain('signature=');
        $response->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=3600')
            ->and($response->streamedContent())->toBe(Storage::disk('local')->get($asset->path));
    });

    it('gives the same URL an hour later', function () {
        $asset = servedImage(servedArticle());
        $first = $this->assets->url($asset);

        $this->travel(61)->minutes();

        expect($this->assets->url($asset))->toBe($first);
    });

    it('ignores the file name in the URL', function () {
        $asset = servedImage(servedArticle());

        $this->get(route(ArticleAssets::ROUTE, ['asset' => $asset->uuid, 'name' => 'other.jpg']))->assertOk();
        $this->get(route(ArticleAssets::ROUTE, ['asset' => $asset->uuid]))->assertOk();
    });
});

describe('Articles that are not public', function () {
    it('refuses the plain URL of an internal article and serves its signed URL privately', function () {
        $asset = servedImage(servedArticle(['visibility' => ArticleVisibility::Internal]));

        $signed = $this->assets->url($asset);

        $this->get(plainUrl($asset))->assertNotFound();
        expect($signed)->toContain('signature=');
        $response = $this->get($signed)->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    });

    it('treats a draft as not public', function () {
        $asset = servedImage(servedArticle(published: false));

        $this->get(plainUrl($asset))->assertNotFound();
        $this->get($this->assets->url($asset))->assertOk();
    });

    it('treats a public article in an internal category as not public', function () {
        $this->category->update(['visibility' => 'internal']);
        $asset = servedImage(servedArticle());

        $this->get(plainUrl($asset))->assertNotFound();
        $this->get($this->assets->url($asset))->assertOk();
    });

    it('treats a trashed article as not public', function () {
        $article = servedArticle();
        $asset = servedImage($article);
        $article->delete();

        $this->get(plainUrl($asset))->assertNotFound();
        $this->get($this->assets->url($asset))->assertOk();
    });

    it('stops serving the plain URL as soon as the article becomes internal, without moving the file', function () {
        $article = servedArticle();
        $asset = servedImage($article);
        $url = $this->assets->url($asset);
        $this->get($url)->assertOk();

        $article->update(['visibility' => ArticleVisibility::Internal]);

        $this->get($url)->assertNotFound();
        expect(Storage::disk('local')->exists($asset->path))->toBeTrue();
    });

    it('stops serving the plain URL when a parent category becomes internal', function () {
        $child = Category::factory()->withParent($this->category)->create();
        $asset = servedImage(servedArticle(['category_id' => $child->id]));
        $url = $this->assets->url($asset);
        $this->get($url)->assertOk();

        $this->category->update(['visibility' => 'internal']);

        $this->get($url)->assertNotFound();
    });

    it('refuses an expired signed URL', function () {
        $asset = servedImage(servedArticle(['visibility' => ArticleVisibility::Internal]));
        $signed = $this->assets->url($asset);

        $this->travel(91)->minutes();

        $this->get($signed)->assertNotFound();
    });

    it('signs for the configured minutes, rounded up to the end of the hour', function () {
        $this->travelTo(now()->setTime(10, 40));
        $asset = servedImage(servedArticle(['visibility' => ArticleVisibility::Internal]));

        parse_str((string) parse_url($this->assets->url($asset), PHP_URL_QUERY), $query);

        expect((int) $query['expires'])->toBe(now()->setTime(11, 59, 59)->getTimestamp());
    });
});

describe('Requests that find nothing', function () {
    it('answers not found for an unknown asset', function () {
        $this->get(route(ArticleAssets::ROUTE, ['asset' => '9b2b1f7e-0000-4000-8000-000000000000', 'name' => 'x.jpg']))->assertNotFound();
        $this->get('/knowledge-base/assets/not-a-uuid/x.jpg')->assertNotFound();
    });

    it('answers not found when the file is missing', function () {
        $asset = servedImage(servedArticle());
        Storage::disk('local')->delete($asset->path);

        $this->get($this->assets->url($asset))->assertNotFound();
    });
});

describe('Disks', function () {
    it('serves an asset from the disk it was stored on after the configured disk changes', function () {
        Storage::fake('public');
        config(['knowledge-base.attachments.disk' => 'public']);
        $asset = servedImage(servedArticle());

        config(['knowledge-base.attachments.disk' => 'local']);

        $this->get($this->assets->url($asset))->assertOk();
        expect($asset->fresh()->disk)->toBe('public');
    });

    it('keeps scripts in an allowed SVG from running when it is opened directly', function () {
        config(['knowledge-base.attachments.images.accepted_types' => ['image/svg+xml']]);
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $asset = $this->assets->store(servedArticle(), $svg, AssetKind::Image);

        $response = $this->get($this->assets->url($asset))->assertOk();

        expect($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
    });
});

describe('Serving turned off', function () {
    it('registers no route', function () {
        config(['knowledge-base.attachments.route.enabled' => false]);
        Route::setRoutes(new RouteCollection);

        require __DIR__.'/../../routes/knowledge-base.php';

        expect(Route::has(ArticleAssets::ROUTE))->toBeFalse();
    });

    it('registers the route under the configured prefix', function () {
        config(['knowledge-base.attachments.route.prefix' => '/help/files/']);
        Route::setRoutes(new RouteCollection);

        require __DIR__.'/../../routes/knowledge-base.php';
        Route::getRoutes()->refreshNameLookups();

        expect(route(ArticleAssets::ROUTE, ['asset' => '9b2b1f7e-0000-4000-8000-000000000000', 'name' => 'x.jpg']))
            ->toBe('http://localhost/help/files/9b2b1f7e-0000-4000-8000-000000000000/x.jpg');
    });
});

describe('Content with served URLs', function () {
    it('recognises a file link saved with a signed URL, and points it at the current URL', function () {
        $article = servedArticle(['visibility' => ArticleVisibility::Internal]);
        $pdf = UploadedFile::fake()->createWithContent('warranty.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
        $asset = $this->assets->store($article, $pdf, AssetKind::File);
        $saved = $this->assets->url($asset);
        $article->update(['content' => '<p><a href="'.e($saved).'">warranty.pdf, 1 KB</a></p>']);

        $this->travel(2)->hours();
        $resolved = $this->assets->resolve((string) $article->content, $article);

        expect($this->assets->usage($asset))->toBe(['en'])
            ->and($resolved)->toContain('href="'.e($this->assets->url($asset)).'"')
            ->and($resolved)->not->toContain(e($saved));
    });

    it('unlinks a served link to a deleted file for readers and reports it as broken', function () {
        $article = servedArticle();
        $pdf = UploadedFile::fake()->createWithContent('warranty.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
        $asset = $this->assets->store($article, $pdf, AssetKind::File);
        $article->update(['content' => '<p><a href="'.$this->assets->url($asset).'">warranty.pdf</a></p>']);

        $this->assets->delete($asset);

        expect($this->assets->resolve((string) $article->content, $article, forPublic: true))->toBe('<p>warranty.pdf</p>')
            ->and($this->assets->brokenReferences($article->fresh()))->toBe(['en' => [$asset->uuid]]);
    });

    it('gives a public article plain URLs and a non-public one signed URLs', function () {
        $public = servedArticle();
        $internal = servedArticle(['visibility' => ArticleVisibility::Internal]);
        $publicImage = servedImage($public);
        $internalImage = servedImage($internal);

        $markup = fn ($asset): string => '<p><img data-id="'.$asset->uuid.'" src="x" alt=""></p>';

        expect($this->assets->resolve($markup($publicImage), $public))->not->toContain('signature=')
            ->and($this->assets->resolve($markup($internalImage), $internal))->toContain('signature=');
    });
});

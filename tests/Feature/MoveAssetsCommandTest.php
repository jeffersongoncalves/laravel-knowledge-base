<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\KnowledgeBase\Enums\AssetKind;
use JeffersonGoncalves\KnowledgeBase\Models\Article;
use JeffersonGoncalves\KnowledgeBase\Models\Category;
use JeffersonGoncalves\KnowledgeBase\Support\ArticleAssets;
use JeffersonGoncalves\KnowledgeBase\Tests\Fixtures\User;

beforeEach(function () {
    config([
        'knowledge-base.locales' => ['en', 'de'],
        'knowledge-base.default_locale' => 'en',
        'knowledge-base.attachments.disk' => 'public',
    ]);

    Storage::fake('public');
    Storage::fake('local');

    User::createTable();
    $this->assets = app(ArticleAssets::class);
    $user = User::create(['name' => 'Staff', 'email' => 'staff@example.com']);

    $this->article = Article::factory()->published()->create([
        'author_type' => $user->getMorphClass(),
        'author_id' => $user->id,
        'category_id' => Category::factory()->create()->id,
    ]);

    // Stored with v2.0.0's default, then the app switches to the private disk.
    $this->shared = $this->assets->store($this->article, UploadedFile::fake()->image('fern.jpg'), AssetKind::Image);
    $this->german = $this->assets->store($this->article, UploadedFile::fake()->image('kontoseite.png'), AssetKind::Image, 'de');

    config(['knowledge-base.attachments.disk' => 'local']);
});

it('moves every asset to the configured disk, keeping its path', function () {
    $contents = Storage::disk('public')->get($this->shared->path);

    $this->artisan('kb:move-assets')
        ->expectsOutputToContain("Moved 2 asset(s) to the 'local' disk.")
        ->assertSuccessful();

    foreach ([$this->shared, $this->german] as $asset) {
        $fresh = $asset->fresh();

        expect($fresh->disk)->toBe('local')
            ->and($fresh->path)->toBe($asset->path)
            ->and(Storage::disk('local')->exists($asset->path))->toBeTrue()
            ->and(Storage::disk('public')->exists($asset->path))->toBeFalse();
    }

    expect(Storage::disk('local')->get($this->shared->path))->toBe($contents);
});

it('removes the folders it leaves empty on the old disk', function () {
    $folder = 'knowledge-base/articles/'.$this->article->uuid;

    $this->artisan('kb:move-assets')->assertSuccessful();

    expect(Storage::disk('public')->directoryExists($folder))->toBeFalse();
});

it('keeps serving the moved assets', function () {
    $this->artisan('kb:move-assets')->assertSuccessful();

    $this->get($this->assets->url($this->shared->fresh()))->assertOk();
});

it('lists without moving on a dry run', function () {
    $this->artisan('kb:move-assets --dry-run')
        ->expectsOutputToContain('public:'.$this->shared->path.' → local:'.$this->shared->path)
        ->expectsOutputToContain("Would move 2 asset(s) to the 'local' disk.")
        ->assertSuccessful();

    expect($this->shared->fresh()->disk)->toBe('public')
        ->and(Storage::disk('public')->exists($this->shared->path))->toBeTrue();
});

it('says so when there is nothing to move', function () {
    $this->artisan('kb:move-assets')->assertSuccessful();

    $this->artisan('kb:move-assets')
        ->expectsOutputToContain("Every asset is already on the 'local' disk.")
        ->assertSuccessful();
});

it('finishes the record of a file an interrupted run already moved', function () {
    Storage::disk('local')->put($this->shared->path, Storage::disk('public')->get($this->shared->path));
    Storage::disk('public')->delete($this->shared->path);

    $this->artisan('kb:move-assets')->assertSuccessful();

    expect($this->shared->fresh()->disk)->toBe('local');
});

it('reports an asset whose file is missing, leaves it alone and fails', function () {
    Storage::disk('public')->delete($this->german->path);

    $this->artisan('kb:move-assets')
        ->expectsOutputToContain('Could not move public:'.$this->german->path)
        ->assertFailed();

    expect($this->german->fresh()->disk)->toBe('public')
        ->and($this->shared->fresh()->disk)->toBe('local');
});

it('purges an article folder on every disk its assets used', function () {
    $this->assets->store($this->article, UploadedFile::fake()->image('new.jpg'), AssetKind::Image);
    $folder = 'knowledge-base/articles/'.$this->article->uuid;

    $this->article->forceDelete();

    expect(Storage::disk('public')->directoryExists($folder))->toBeFalse()
        ->and(Storage::disk('local')->directoryExists($folder))->toBeFalse();
});

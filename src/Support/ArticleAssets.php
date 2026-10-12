<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Carbon\CarbonInterface;
use DOMDocument;
use DOMElement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JeffersonGoncalves\KnowledgeBase\Enums\AssetKind;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleAssetContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\TranslatableContract;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;

/**
 * Stores, locates and cleans up the images and files an article owns (see
 * design D2, D3, D7 and D9). Everything that touches article assets goes
 * through here, so the disk layout, the allowed types and the "one folder per
 * article" rule live in one place.
 */
class ArticleAssets
{
    /** The route that serves assets (see the `attachments.route` config). */
    public const ROUTE = 'knowledge-base.assets.show';

    /**
     * The configured disk for new assets.
     */
    public function disk(): string
    {
        return (string) config('knowledge-base.attachments.disk', 'public');
    }

    /**
     * The configured root directory, without a trailing slash.
     */
    public function directory(): string
    {
        return trim((string) config('knowledge-base.attachments.directory', 'knowledge-base'), '/');
    }

    /**
     * Store an image or file for an article.
     *
     * The default language's assets are the article's shared assets, so a
     * default-language scope is stored as shared (design D7). An unconfigured
     * language is rejected.
     *
     * @param  UploadedFile|string  $file  an upload, or the path to a local file
     *
     * @throws InvalidArgumentException when the language is not configured
     * @throws ValidationException when the file's type or size is not allowed
     * @throws RuntimeException when the disk reports the write failed
     */
    public function store(Model&ArticleContract $article, UploadedFile|string $file, AssetKind $kind, ?string $locale = null): Model&ArticleAssetContract
    {
        $scope = $this->scopeLocale($locale);
        $source = $file instanceof UploadedFile ? $file : new File($file);

        $this->validate($source, $kind);

        $mime = (string) $source->getMimeType();
        $originalName = $file instanceof UploadedFile
            ? $file->getClientOriginalName()
            : $source->getFilename();

        $disk = $this->disk();
        $directory = $this->directoryFor($article, $scope);
        $path = $directory.'/'.$this->fileName($originalName, $mime, $source->guessExtension());

        $written = $file instanceof UploadedFile
            ? Storage::disk($disk)->putFileAs($directory, $file, basename($path))
            : Storage::disk($disk)->put($path, (string) file_get_contents($source->getRealPath()));

        // A disk that doesn't throw reports a failed write as false: no record for a missing file.
        if ($written === false) {
            throw new RuntimeException("Could not write the asset file {$disk}:{$path}.");
        }

        [$width, $height] = $this->dimensions($source, $kind);

        $assetClass = ModelResolver::articleAsset();

        /** @var Model&ArticleAssetContract $asset */
        $asset = $assetClass::create([
            'article_id' => $article->getKey(),
            'kind' => $kind,
            'locale' => $scope,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $originalName,
            'mime_type' => $mime,
            'size' => (int) $source->getSize(),
            'width' => $width,
            'height' => $height,
        ]);

        return $asset;
    }

    /**
     * The current URL of an asset (design D5). Never stored in content:
     * content refers to the asset by its uuid.
     *
     * Served through the core's route: a plain, stable URL when the article is
     * publicly visible, a temporary signed URL otherwise. With the route
     * turned off, the disk's own URL. Pass `$public` when the article's
     * visibility is already known, to skip looking it up again.
     */
    public function url(Model&ArticleAssetContract $asset, ?bool $public = null): string
    {
        if (! $this->served()) {
            return Storage::disk((string) $asset->getAttribute('disk'))->url((string) $asset->getAttribute('path'));
        }

        $parameters = [
            'asset' => (string) $asset->getAttribute('uuid'),
            'name' => basename((string) $asset->getAttribute('path')),
        ];

        if ($public ?? $this->isServedPublicly($asset)) {
            return URL::route(self::ROUTE, $parameters);
        }

        return URL::temporarySignedRoute(self::ROUTE, $this->signedUrlExpiry(), $parameters);
    }

    /**
     * Whether assets are served through the core's route.
     */
    public function served(): bool
    {
        return (bool) config('knowledge-base.attachments.route.enabled', true);
    }

    /**
     * Whether anyone may open the asset without a signature: its article
     * exists, isn't trashed and is publicly visible.
     */
    public function isServedPublicly(Model&ArticleAssetContract $asset): bool
    {
        // The relation leaves out a soft-deleted article: it counts as not public.
        $article = $asset->article()->first();

        return $article instanceof Model && $this->articleIsServedPublicly($article);
    }

    /**
     * The single visibility rule, plus: a trashed article is never public.
     */
    protected function articleIsServedPublicly(Model $article): bool
    {
        if (method_exists($article, 'trashed') && $article->trashed()) {
            return false;
        }

        return Visibility::articleIsPubliclyVisible($article);
    }

    /**
     * When a signed URL made now expires: the configured minutes, rounded up
     * to the end of the hour (as Filament does), so a URL stays the same for
     * a while and browsers can reuse a loaded image.
     */
    protected function signedUrlExpiry(): CarbonInterface
    {
        $minutes = max(1, (int) config('knowledge-base.attachments.route.signed_url_minutes', 30));

        return now()->addMinutes($minutes)->endOfHour();
    }

    /**
     * Turn article content into displayable HTML with every asset reference
     * pointing at the asset's current URL (design D4 and D5).
     *
     * Images carry Filament's own `data-id`; file links are recognised by their
     * asset path, because Filament's link mark keeps only `href` and `target`
     * (the D4 spike). A `data-kb-asset` attribute is honoured when present.
     *
     * For public readers a reference to an asset that no longer exists (or
     * belongs to another article) is dropped (images) or unlinked to plain text
     * (file links). Content without an asset reference is left untouched.
     */
    public function resolve(string $html, Model&ArticleContract $article, bool $forPublic = false): string
    {
        if ($html === '' || (! str_contains($html, '<img') && ! str_contains($html, '<a'))) {
            return $html;
        }

        $index = $this->assetIndex($article);

        // Decided once per call, not per reference: it costs a category query.
        $public = $this->served() && $this->articleIsServedPublicly($article);

        return $this->transform($html, function (DOMElement $root) use ($index, $article, $forPublic, $public): void {
            foreach (iterator_to_array($root->getElementsByTagName('img')) as $image) {
                $id = trim($image->getAttribute('data-id'));

                if ($id === '') {
                    continue; // content from before assets: leave it as it is
                }

                $asset = $index['byUuid'][$id] ?? null;

                if ($asset instanceof Model) {
                    $image->setAttribute('src', $this->url($asset, $public));

                    continue;
                }

                if ($forPublic) {
                    $image->parentNode?->removeChild($image);
                }
            }

            foreach (iterator_to_array($root->getElementsByTagName('a')) as $link) {
                $reference = $this->linkReference($link, $index, $article);

                if ($reference === null) {
                    continue; // an ordinary link, or content from before assets
                }

                if ($reference['asset'] instanceof Model) {
                    $link->setAttribute('href', $this->url($reference['asset'], $public));

                    continue;
                }

                if ($forPublic) {
                    $this->unlink($link);
                }
            }
        });
    }

    /**
     * The languages of its article that currently use an asset, based on the
     * current content of every version (design D6). The version history is not
     * scanned.
     *
     * @return list<string>
     */
    public function usage(Model&ArticleAssetContract $asset): array
    {
        $uuid = (string) $asset->getAttribute('uuid');
        $article = $asset->article()->first();

        if (! $article instanceof Model || ! $article instanceof ArticleContract) {
            return [];
        }

        $index = $this->assetIndex($article);
        $used = [];

        foreach ($this->currentVersions($article) as $locale => $content) {
            if (in_array($uuid, $this->scan($content, $index, $article)['used'], true)) {
                $used[] = $locale;
            }
        }

        return $used;
    }

    /**
     * The references in an article's current content that point at no existing
     * asset of the article, per language (design D6). Empty again as soon as
     * the content no longer contains them.
     *
     * @return array<string, list<string>>
     */
    public function brokenReferences(Model&ArticleContract $article): array
    {
        $broken = [];
        $index = $this->assetIndex($article);

        foreach ($this->currentVersions($article) as $locale => $content) {
            $missing = $this->scan($content, $index, $article)['missing'];

            if ($missing !== []) {
                $broken[$locale] = $missing;
            }
        }

        return $broken;
    }

    /**
     * Remove an asset's row and, through the model's `deleted` event, its file.
     * Deleting an asset that is still used is allowed; callers show its usage
     * first (design D7).
     */
    public function delete(Model&ArticleAssetContract $asset): void
    {
        $asset->delete();
    }

    /**
     * Remove every asset of one language version (design D7).
     */
    public function deleteForLocale(Model&ArticleContract $article, string $locale): void
    {
        foreach ($this->assetsOf($article) as $asset) {
            if ($asset->getAttribute('locale') === $locale) {
                $this->delete($asset);
            }
        }
    }

    /**
     * Remove every file of an article and its folder on the configured disk,
     * used when an article is force-deleted (design D7). The rows themselves
     * cascade with the article.
     */
    public function purge(Model&ArticleContract $article): void
    {
        // Remove the rows explicitly as well as their files: the foreign-key
        // cascade covers the rows on databases that enforce it, but deleting
        // them here keeps the behaviour identical everywhere.
        // The folder can exist on every disk the article's assets were stored
        // on (the configured disk may have changed since, design D5).
        $disks = [$this->disk() => true];

        foreach ($this->assetsOf($article) as $asset) {
            $disks[(string) $asset->getAttribute('disk')] = true;
            $this->delete($asset);
        }

        foreach (array_keys($disks) as $disk) {
            Storage::disk($disk)->deleteDirectory($this->articleDirectory($article));
        }
    }

    /**
     * @return iterable<int, Model&ArticleAssetContract>
     */
    protected function assetsOf(Model&ArticleContract $article): iterable
    {
        // Use the relation when a caller (the admin list) eager-loaded it, so
        // per-row usage and broken-reference checks stay one query per page.
        if ($article->relationLoaded('assets')) {
            return $article->getRelation('assets');
        }

        $assetClass = ModelResolver::articleAsset();

        return $assetClass::query()->where('article_id', $article->getKey())->get();
    }

    /**
     * The article's assets indexed by uuid (for images) and by stored path (for
     * the path-based file links).
     *
     * @return array{
     *     byUuid: array<string, Model&ArticleAssetContract>,
     *     byPath: array<string, Model&ArticleAssetContract>
     * }
     */
    protected function assetIndex(Model&ArticleContract $article): array
    {
        $byUuid = [];
        $byPath = [];

        foreach ($this->assetsOf($article) as $asset) {
            $byUuid[(string) $asset->getAttribute('uuid')] = $asset;
            $byPath[(string) $asset->getAttribute('path')] = $asset;
        }

        return ['byUuid' => $byUuid, 'byPath' => $byPath];
    }

    /**
     * The current content of every language version, keyed by locale (design
     * D6). The article's own row carries the default-language content.
     *
     * @return array<string, string>
     */
    protected function currentVersions(Model&ArticleContract $article): array
    {
        $versions = [Locales::default() => (string) $article->getAttribute('content')];

        if ($article instanceof TranslatableContract) {
            $translations = $article->relationLoaded('translations')
                ? $article->getRelation('translations')
                : $article->translations()->get();

            foreach ($translations as $translation) {
                $versions[(string) $translation->getAttribute('locale')] = (string) $translation->getAttribute('content');
            }
        }

        return $versions;
    }

    /**
     * The asset references in a piece of content: the uuids that resolve to an
     * asset of the article, and the identifiers that resolve to nothing.
     *
     * @param  array{
     *     byUuid: array<string, Model&ArticleAssetContract>,
     *     byPath: array<string, Model&ArticleAssetContract>
     * }  $index
     * @return array{used: list<string>, missing: list<string>}
     */
    protected function scan(string $html, array $index, Model&ArticleContract $article): array
    {
        $used = [];
        $missing = [];

        if ($html === '') {
            return ['used' => [], 'missing' => []];
        }

        $this->transform($html, function (DOMElement $root) use ($index, $article, &$used, &$missing): void {
            foreach (iterator_to_array($root->getElementsByTagName('img')) as $image) {
                $id = trim($image->getAttribute('data-id'));

                if ($id === '') {
                    continue;
                }

                $asset = $index['byUuid'][$id] ?? null;

                if ($asset instanceof Model) {
                    $used[] = (string) $asset->getAttribute('uuid');
                } else {
                    $missing[] = $id;
                }
            }

            foreach (iterator_to_array($root->getElementsByTagName('a')) as $link) {
                $reference = $this->linkReference($link, $index, $article);

                if ($reference === null) {
                    continue;
                }

                if ($reference['asset'] instanceof Model) {
                    $used[] = (string) $reference['asset']->getAttribute('uuid');
                } else {
                    $missing[] = $reference['identifier'];
                }
            }
        });

        return [
            'used' => array_values(array_unique($used)),
            'missing' => array_values(array_unique($missing)),
        ];
    }

    /**
     * Which asset a link points at, or null when it is an ordinary link.
     *
     * @param  array{
     *     byUuid: array<string, Model&ArticleAssetContract>,
     *     byPath: array<string, Model&ArticleAssetContract>
     * }  $index
     * @return array{asset: (Model&ArticleAssetContract)|null, identifier: string}|null
     */
    protected function linkReference(DOMElement $link, array $index, Model&ArticleContract $article): ?array
    {
        $identifier = trim($link->getAttribute('data-kb-asset'));

        if ($identifier !== '') {
            return ['asset' => $index['byUuid'][$identifier] ?? null, 'identifier' => $identifier];
        }

        if ($link->getAttribute('href') === '') {
            return null;
        }

        $href = urldecode((string) strtok($link->getAttribute('href'), '?#'));

        // A known asset path anywhere in the URL: robust to a changed site
        // address, disk prefix or asset directory (design D4 fallback).
        foreach ($index['byPath'] as $path => $asset) {
            if ($path !== '' && str_contains($href, $path)) {
                return ['asset' => $asset, 'identifier' => $path];
            }
        }

        // A served URL names the asset by its uuid (design D4).
        $served = $this->servedReference($href);

        if ($served !== null) {
            return ['asset' => $index['byUuid'][$served] ?? null, 'identifier' => $served];
        }

        foreach ($index['byUuid'] as $uuid => $asset) {
            if ($uuid !== '' && str_contains($href, '/'.$uuid.'/')) {
                return ['asset' => $asset, 'identifier' => $uuid];
            }
        }

        // Otherwise recognise the article's folder, so a link to a deleted file
        // is still reported and unlinked for readers.
        $relative = $this->assetRelativePart($href, $article);

        if ($relative !== null) {
            return ['asset' => null, 'identifier' => $relative];
        }

        return null;
    }

    /**
     * The asset uuid in a URL of the serving route, or null when the URL does
     * not point at the route.
     */
    protected function servedReference(string $href): ?string
    {
        $prefix = trim((string) config('knowledge-base.attachments.route.prefix', 'knowledge-base/assets'), '/');

        if ($prefix === '' || preg_match('~/'.preg_quote($prefix, '~').'/([0-9a-fA-F-]{36})(?:/|$)~', $href, $match) !== 1) {
            return null;
        }

        return Str::isUuid($match[1]) ? strtolower($match[1]) : null;
    }

    /**
     * The part of the URL inside `articles/{article uuid}/`, or null when the
     * URL does not point into the article's folder.
     */
    protected function assetRelativePart(string $href, Model&ArticleContract $article): ?string
    {
        $needle = '/articles/'.(string) $article->getAttribute('uuid').'/';
        $position = strpos($href, $needle);

        if ($position === false) {
            return null;
        }

        return substr($href, $position + strlen($needle));
    }

    /**
     * Run a callback over the nodes of a wrapped document and return the
     * serialised content, using the same UTF-8 handling as the demo's
     * ArticleContent (design D5).
     *
     * @param  callable(DOMElement): void  $callback
     */
    protected function transform(string $html, callable $callback): string
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><div id="kb-assets-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('kb-assets-root');

        if (! $root instanceof DOMElement) {
            return $html;
        }

        $callback($root);

        $output = '';

        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return $output;
    }

    /**
     * Replace a link with its own text and children.
     */
    protected function unlink(DOMElement $link): void
    {
        $parent = $link->parentNode;

        if ($parent === null) {
            return;
        }

        while ($link->firstChild !== null) {
            $parent->insertBefore($link->firstChild, $link);
        }

        $parent->removeChild($link);
    }

    /**
     * The stored scope: null for shared, the language for a non-default one.
     */
    protected function scopeLocale(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        if (! Locales::isConfigured($locale)) {
            throw new InvalidArgumentException("Locale [{$locale}] is not configured.");
        }

        // The default language's assets are the article's shared assets.
        return $locale === Locales::default() ? null : $locale;
    }

    /**
     * Validate the file's detected type and size for its kind (design D9).
     */
    protected function validate(SymfonyFile $file, AssetKind $kind): void
    {
        $group = $kind === AssetKind::Image ? 'images' : 'files';

        /** @var list<string> $accepted */
        $accepted = (array) config("knowledge-base.attachments.{$group}.accepted_types", []);
        $maxSize = (int) config("knowledge-base.attachments.{$group}.max_size", 0);

        $rules = array_values(array_filter([
            'required',
            'file',
            $maxSize > 0 ? 'max:'.$maxSize : null,
            $accepted !== [] ? 'mimetypes:'.implode(',', $accepted) : null,
        ]));

        $validator = Validator::make(['file' => $file], ['file' => $rules]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    protected function dimensions(SymfonyFile $file, AssetKind $kind): array
    {
        if ($kind !== AssetKind::Image) {
            return [null, null];
        }

        $size = @getimagesize($file->getRealPath());

        if ($size === false) {
            return [null, null];
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /**
     * A readable name from the original, plus a short random code and the
     * extension of the detected type (design D3).
     */
    protected function fileName(string $originalName, string $mime, ?string $guessedExtension): string
    {
        $base = Str::limit(Str::slug(pathinfo($originalName, PATHINFO_FILENAME)), 60, '');

        if ($base === '') {
            $base = 'file';
        }

        $extension = $this->extensionFor($mime) ?? $guessedExtension ?? 'bin';

        return $base.'-'.substr(bin2hex(random_bytes(3)), 0, 6).'.'.$extension;
    }

    protected function extensionFor(string $mime): ?string
    {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
        ];

        return $extensions[$mime] ?? null;
    }

    /**
     * `{directory}/articles/{article uuid}`, named after the uuid so it stays
     * stable when the title or slug changes (design D3).
     */
    protected function articleDirectory(Model&ArticleContract $article): string
    {
        return $this->directory().'/articles/'.(string) $article->getAttribute('uuid');
    }

    protected function directoryFor(Model&ArticleContract $article, ?string $locale): string
    {
        return $this->articleDirectory($article).'/'.($locale ?? 'shared');
    }
}

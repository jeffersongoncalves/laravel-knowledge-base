<?php

namespace JeffersonGoncalves\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use JeffersonGoncalves\KnowledgeBase\Enums\AssetKind;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleAssetContract;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

/**
 * An image or file owned by one article (see design D2).
 *
 * `locale` is null for a shared asset (usable in every language version) and a
 * configured non-default language otherwise. `disk` and `path` are stored per
 * row, so changing the configured disk later never orphans an existing file.
 *
 * @property int $id
 * @property string $uuid
 * @property int $article_id
 * @property AssetKind $kind
 * @property string|null $locale
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Article $article
 */
class ArticleAsset extends Model implements ArticleAssetContract
{
    protected $fillable = [
        'article_id',
        'kind',
        'locale',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'width',
        'height',
    ];

    protected $casts = [
        'kind' => AssetKind::class,
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
    ];

    public function getTable(): string
    {
        return (config('knowledge-base.table_prefix') ?? '').'article_assets';
    }

    protected static function booted(): void
    {
        static::creating(function (ArticleAsset $asset) {
            if (empty($asset->uuid)) {
                $asset->uuid = (string) Str::uuid();
            }
        });
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::article(), 'article_id');
    }

    public function kind(): AssetKind
    {
        $kind = $this->getAttribute('kind');

        return $kind instanceof AssetKind ? $kind : AssetKind::from((string) $kind);
    }

    public function isShared(): bool
    {
        return $this->getAttribute('locale') === null;
    }
}

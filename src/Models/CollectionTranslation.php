<?php

namespace JeffersonGoncalves\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use JeffersonGoncalves\KnowledgeBase\Models\Concerns\SyncsAuthoritativeTranslation;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CollectionTranslationContract;
use JeffersonGoncalves\KnowledgeBase\Support\Locales;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

/**
 * One language's version of a collection (see design D1).
 *
 * @property int $id
 * @property int $collection_id
 * @property string $locale
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection $collection
 */
class CollectionTranslation extends Model implements CollectionTranslationContract
{
    use HasFactory;
    use SyncsAuthoritativeTranslation;

    protected $fillable = [
        'collection_id',
        'locale',
        'name',
        'slug',
        'description',
    ];

    protected $casts = [
        'collection_id' => 'integer',
    ];

    public function getTable(): string
    {
        return (config('knowledge-base.table_prefix') ?? '').'collection_translations';
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::collection(), 'collection_id');
    }

    public function owner(): BelongsTo
    {
        return $this->collection();
    }

    /** @param Builder<static> $query */
    public function scopeForLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    protected function ownerForeignKey(): string
    {
        return 'collection_id';
    }

    /** @return list<string> */
    protected function mirroredAttributes(): array
    {
        return ['name', 'slug', 'description'];
    }

    protected function authoritativeLocaleValue(): string
    {
        return Locales::default();
    }

    protected function tracksOutdated(): bool
    {
        return false;
    }
}

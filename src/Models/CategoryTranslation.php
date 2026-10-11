<?php

namespace JeffersonGoncalves\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use JeffersonGoncalves\KnowledgeBase\Models\Concerns\SyncsAuthoritativeTranslation;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CategoryTranslationContract;
use JeffersonGoncalves\KnowledgeBase\Support\Locales;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

/**
 * @property int $id
 * @property int $category_id
 * @property string $locale
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category $category
 */
class CategoryTranslation extends Model implements CategoryTranslationContract
{
    use HasFactory;
    use SyncsAuthoritativeTranslation;

    protected $fillable = [
        'category_id',
        'locale',
        'name',
        'slug',
        'description',
    ];

    protected $casts = [
        'category_id' => 'integer',
    ];

    public function getTable(): string
    {
        return (config('knowledge-base.table_prefix') ?? '').'category_translations';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::category(), 'category_id');
    }

    public function owner(): BelongsTo
    {
        return $this->category();
    }

    /** @param Builder<static> $query */
    public function scopeForLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    protected function ownerForeignKey(): string
    {
        return 'category_id';
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

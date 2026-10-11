<?php

namespace JeffersonGoncalves\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use JeffersonGoncalves\KnowledgeBase\Models\Concerns\HasTranslations;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CategoryContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\TranslatableContract;
use JeffersonGoncalves\KnowledgeBase\Support\Locales;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;
use JeffersonGoncalves\KnowledgeBase\Support\Visibility;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $icon
 * @property string $visibility
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Category|null $parent
 * @property-read Collection<int, Category> $children
 * @property-read Collection<int, CategoryTranslation> $translations
 * @property-read Collection<int, Article> $articles
 */
class Category extends Model implements CategoryContract, TranslatableContract
{
    use HasFactory;
    use HasTranslations;
    use SoftDeletes;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'icon',
        'visibility',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getTable(): string
    {
        return (config('knowledge-base.table_prefix') ?? '').'categories';
    }

    /**
     * Categories always have a version in the configured default language
     * (see design D1 and the "Category versions per language" requirement).
     */
    public function authoritativeLocale(): string
    {
        return Locales::default();
    }

    /** @return class-string<Model> */
    protected function translationModel(): string
    {
        return ModelResolver::categoryTranslation();
    }

    protected function translationForeignKey(): string
    {
        return 'category_id';
    }

    /** @return list<string> */
    protected function mirroredAttributes(): array
    {
        return ['name', 'slug', 'description'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::category(), 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ModelResolver::category(), 'parent_id');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(ModelResolver::article(), 'category_id');
    }

    /** @param Builder<static> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Categories that may be shown to customers: active, public and not
     * deleted, with every ancestor likewise.
     *
     * @param  Builder<static>  $query
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn($query->qualifyColumn('id'), Visibility::publicCategoryIds());
    }

    /** @param Builder<static> $query */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    /** @param Builder<static> $query */
    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

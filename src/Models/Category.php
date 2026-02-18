<?php

namespace JeffersonGoncalves\KnowledgeBase\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CategoryContract;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

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
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Model&CategoryContract|null $parent
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model&CategoryContract> $children
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model&\JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract> $articles
 */
class Category extends Model implements CategoryContract
{
    use HasFactory;
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

    /** @return BelongsTo<\Illuminate\Database\Eloquent\Model&CategoryContract, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::category(), 'parent_id');
    }

    /** @return HasMany<\Illuminate\Database\Eloquent\Model&CategoryContract, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(ModelResolver::category(), 'parent_id');
    }

    /** @return HasMany<\Illuminate\Database\Eloquent\Model&\JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(ModelResolver::article(), 'category_id');
    }

    /** @param Builder<static> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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

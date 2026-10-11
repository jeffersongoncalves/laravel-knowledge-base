<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use JeffersonGoncalves\KnowledgeBase\Support\Locales;
use RuntimeException;

/**
 * The translation-side rules (see design D1, D1a, D2 and D5):
 *
 * - a version can only be saved in a configured language,
 * - one version per language per article/category,
 * - a slug belongs to at most one article/category, in any language,
 * - editing a version clears its outdated flag,
 * - saving the authoritative version mirrors it onto the main row,
 * - the authoritative version cannot be deleted.
 */
trait SyncsAuthoritativeTranslation
{
    protected static function bootSyncsAuthoritativeTranslation(): void
    {
        static::saving(function (Model $translation): void {
            if (! $translation instanceof self) {
                return;
            }

            $translation->guardTranslation();
        });

        static::saved(function (Model $translation): void {
            if (! $translation instanceof self || ! $translation->isAuthoritative()) {
                return;
            }

            $translation->mirrorToOwner();
        });

        static::deleting(function (Model $translation): bool {
            if ($translation instanceof self && $translation->isAuthoritative()) {
                throw new RuntimeException('The authoritative version cannot be deleted.');
            }

            return true;
        });
    }

    abstract public function owner(): BelongsTo;

    abstract protected function ownerForeignKey(): string;

    /** @return list<string> */
    abstract protected function mirroredAttributes(): array;

    /**
     * The language of the version the owner mirrors.
     */
    abstract protected function authoritativeLocaleValue(): string;

    /**
     * Whether this table stores the outdated flag (articles do, categories
     * have no such column).
     */
    protected function tracksOutdated(): bool
    {
        return true;
    }

    public function isAuthoritative(): bool
    {
        return $this->getAttribute('locale') === $this->authoritativeLocaleValue();
    }

    public function isOutdated(): bool
    {
        return (bool) $this->getAttribute('is_outdated');
    }

    public function markUpToDate(): static
    {
        $this->setAttribute('is_outdated', false);
        $this->setAttribute('outdated_at', null);
        $this->saveQuietly();

        return $this;
    }

    public function flagOutdated(): static
    {
        $this->setAttribute('is_outdated', true);
        $this->setAttribute('outdated_at', now());
        $this->saveQuietly();

        return $this;
    }

    protected function guardTranslation(): void
    {
        $locale = (string) $this->getAttribute('locale');

        if (! Locales::isConfigured($locale)) {
            throw new InvalidArgumentException("Locale [{$locale}] is not configured.");
        }

        $this->guardUniqueLocale($locale);
        $this->guardSlugOwnership();
        $this->clearOutdatedOnContentChange();
    }

    protected function guardUniqueLocale(string $locale): void
    {
        $exists = static::query()
            ->where($this->ownerForeignKey(), $this->getAttribute($this->ownerForeignKey()))
            ->where('locale', $locale)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException("A version in [{$locale}] already exists for this record.");
        }
    }

    protected function guardSlugOwnership(): void
    {
        $conflict = static::query()
            ->where('slug', $this->getAttribute('slug'))
            ->where($this->ownerForeignKey(), '!=', $this->getAttribute($this->ownerForeignKey()))
            ->exists();

        if ($conflict) {
            throw new InvalidArgumentException(
                "The slug [{$this->getAttribute('slug')}] already belongs to another record."
            );
        }
    }

    protected function clearOutdatedOnContentChange(): void
    {
        if (! $this->tracksOutdated() || ! $this->exists) {
            return;
        }

        if ($this->isDirty($this->mirroredAttributes()) && ! $this->isDirty('is_outdated')) {
            $this->setAttribute('is_outdated', false);
            $this->setAttribute('outdated_at', null);
        }
    }

    protected function mirrorToOwner(): void
    {
        $owner = $this->owner()->first();

        if (! $owner instanceof Model) {
            return;
        }

        foreach ($this->mirroredAttributes() as $attribute) {
            $owner->setAttribute($attribute, $this->getAttribute($attribute));
        }

        $owner->saveQuietly();
    }
}

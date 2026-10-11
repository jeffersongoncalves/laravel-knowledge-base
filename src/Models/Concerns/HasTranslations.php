<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Keeps the main row and its authoritative translation mirrored (see design
 * D1). Every write goes through the `saved` event of the model using this
 * trait, so there is a single place where the mirror can drift.
 *
 * The mirror is written with `saveQuietly()`, which is what stops the two
 * directions from calling each other forever.
 */
trait HasTranslations
{
    /** Guards the two-way mirror against re-entering itself. */
    protected static bool $syncingTranslations = false;

    protected static function bootHasTranslations(): void
    {
        static::saved(function (Model $model): void {
            if (! $model instanceof self || static::$syncingTranslations) {
                return;
            }

            $model->syncAuthoritativeTranslation();
        });
    }

    /**
     * The language whose version this row mirrors.
     */
    abstract public function authoritativeLocale(): string;

    /** @return class-string<Model> */
    abstract protected function translationModel(): string;

    abstract protected function translationForeignKey(): string;

    /** @return list<string> */
    abstract protected function mirroredAttributes(): array;

    /**
     * The main-row attributes that mirror the authoritative version. Public so
     * the drift checker can compare both sides without duplicating the list.
     *
     * @return list<string>
     */
    public function translationAttributes(): array
    {
        return $this->mirroredAttributes();
    }

    public function translations(): HasMany
    {
        return $this->hasMany($this->translationModel(), $this->translationForeignKey());
    }

    public function translation(string $locale): ?Model
    {
        return $this->translations()->where('locale', $locale)->first();
    }

    public function authoritativeTranslation(): ?Model
    {
        return $this->translation($this->authoritativeLocale());
    }

    protected function syncAuthoritativeTranslation(): void
    {
        $locale = $this->authoritativeLocale();

        $translation = $this->translations()->where('locale', $locale)->first();

        if (! $this->translationNeedsSync($translation)) {
            return;
        }

        if (! $translation instanceof Model) {
            $translation = $this->translations()->make(['locale' => $locale]);
        }

        foreach ($this->mirroredAttributes() as $attribute) {
            $translation->setAttribute($attribute, $this->getAttribute($attribute));
        }

        $this->applyAuthoritativeStatus($translation);

        static::$syncingTranslations = true;

        try {
            $translation->saveQuietly();
        } finally {
            static::$syncingTranslations = false;
        }
    }

    protected function translationNeedsSync(?Model $translation): bool
    {
        if (! $translation instanceof Model) {
            return true;
        }

        foreach ($this->mirroredAttributes() as $attribute) {
            if ($translation->getAttribute($attribute) != $this->getAttribute($attribute)) {
                return true;
            }
        }

        return $this->authoritativeStatusDiffers($translation);
    }

    /**
     * Whether the authoritative version's status has to follow this row.
     */
    protected function authoritativeStatusDiffers(Model $translation): bool
    {
        return false;
    }

    /**
     * Propagate this row's status to its authoritative version.
     */
    protected function applyAuthoritativeStatus(Model $translation): void
    {
        // Articles mirror their status; categories have none.
    }
}

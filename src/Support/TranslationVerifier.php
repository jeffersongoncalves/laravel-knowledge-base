<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use JeffersonGoncalves\KnowledgeBase\Models\Concerns\HasTranslations;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CategoryContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\TranslatableContract;

/**
 * Reports drift between a main row and its authoritative translation (see
 * design D1 and the "Mirror drifts" risk). Reads only; the mirror itself is
 * written by {@see HasTranslations}.
 */
class TranslationVerifier
{
    /**
     * @return list<string>
     */
    public function drift(): array
    {
        return array_merge($this->articleDrift(), $this->categoryDrift());
    }

    /**
     * @return list<string>
     */
    protected function articleDrift(): array
    {
        $lines = [];

        foreach ($this->rows(ModelResolver::article()) as $article) {
            if (! $article instanceof ArticleContract || ! $article instanceof TranslatableContract) {
                continue;
            }

            $locale = (string) $article->getAttribute('source_locale');
            $translation = $article->authoritativeTranslation();

            if (! $translation instanceof Model) {
                $lines[] = "Article #{$article->getKey()} has no authoritative version in [{$locale}].";

                continue;
            }

            foreach ($article->translationAttributes() as $attribute) {
                if ($translation->getAttribute($attribute) !== $article->getAttribute($attribute)) {
                    $lines[] = "Article #{$article->getKey()} [{$locale}] differs in [{$attribute}].";
                }
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    protected function categoryDrift(): array
    {
        $lines = [];
        $locale = Locales::default();

        foreach ($this->rows(ModelResolver::category()) as $category) {
            if (! $category instanceof CategoryContract || ! $category instanceof TranslatableContract) {
                continue;
            }

            $translation = $category->authoritativeTranslation();

            if (! $translation instanceof Model) {
                $lines[] = "Category #{$category->getKey()} has no version in [{$locale}].";

                continue;
            }

            foreach ($category->translationAttributes() as $attribute) {
                if ($translation->getAttribute($attribute) !== $category->getAttribute($attribute)) {
                    $lines[] = "Category #{$category->getKey()} [{$locale}] differs in [{$attribute}].";
                }
            }
        }

        return $lines;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return iterable<int, Model>
     */
    protected function rows(string $modelClass): iterable
    {
        /** @var Builder<Model> $query */
        $query = $modelClass::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            // @phpstan-ignore method.notFound (withTrashed() is provided by the SoftDeletes trait)
            $query->withTrashed();
        }

        return $query->orderBy('id')->cursor();
    }
}

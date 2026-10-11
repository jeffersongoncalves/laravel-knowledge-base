<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A public article page (see design D3): the served version, whether it is a
 * fallback, the slug to redirect to when the requested slug is not the served
 * one, the languages the article can be read in, its category breadcrumb and its
 * publicly visible related articles.
 */
final class ArticlePage
{
    /**
     * @param  array<string, string>  $alternates  locale => slug of every readable version
     * @param  list<ResolvedTranslation>  $breadcrumb
     * @param  list<ResolvedTranslation>  $related
     */
    public function __construct(
        public Model $article,
        public Model $translation,
        public string $locale,
        public string $slug,
        public bool $isFallback,
        public ?string $redirectSlug,
        public array $alternates,
        public array $breadcrumb,
        public array $related,
    ) {}
}

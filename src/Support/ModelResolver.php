<?php

namespace JeffersonGoncalves\KnowledgeBase\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleAssetContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleFeedbackContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleRelationContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleTranslationContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleVersionContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CategoryContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CategoryTranslationContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CollectionContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CollectionTranslationContract;

class ModelResolver
{
    /** @var array<string, string> */
    protected static array $cache = [];

    /** @return class-string<Model&ArticleContract> */
    public static function article(): string
    {
        return static::resolve('article', ArticleContract::class);
    }

    /** @return class-string<Model&CategoryContract> */
    public static function category(): string
    {
        return static::resolve('category', CategoryContract::class);
    }

    /** @return class-string<Model&ArticleVersionContract> */
    public static function articleVersion(): string
    {
        return static::resolve('article_version', ArticleVersionContract::class);
    }

    /** @return class-string<Model&ArticleTranslationContract> */
    public static function articleTranslation(): string
    {
        return static::resolve('article_translation', ArticleTranslationContract::class);
    }

    /** @return class-string<Model&CategoryTranslationContract> */
    public static function categoryTranslation(): string
    {
        return static::resolve('category_translation', CategoryTranslationContract::class);
    }

    /** @return class-string<Model&CollectionContract> */
    public static function collection(): string
    {
        return static::resolve('collection', CollectionContract::class);
    }

    /** @return class-string<Model&CollectionTranslationContract> */
    public static function collectionTranslation(): string
    {
        return static::resolve('collection_translation', CollectionTranslationContract::class);
    }

    /** @return class-string<Model&ArticleFeedbackContract> */
    public static function articleFeedback(): string
    {
        return static::resolve('article_feedback', ArticleFeedbackContract::class);
    }

    /** @return class-string<Model&ArticleRelationContract> */
    public static function articleRelation(): string
    {
        return static::resolve('article_relation', ArticleRelationContract::class);
    }

    /** @return class-string<Model&ArticleAssetContract> */
    public static function articleAsset(): string
    {
        return static::resolve('article_asset', ArticleAssetContract::class);
    }

    /**
     * @param  class-string  $contract
     * @return class-string
     *
     * @throws InvalidArgumentException
     */
    protected static function resolve(string $key, string $contract): string
    {
        if (isset(static::$cache[$key])) {
            return static::$cache[$key];
        }

        /** @var class-string|null $model */
        $model = config("knowledge-base.models.{$key}");

        if (! $model || ! class_exists($model)) {
            throw new InvalidArgumentException(
                "Model class for [{$key}] does not exist: {$model}"
            );
        }

        if (! is_a($model, $contract, true)) {
            throw new InvalidArgumentException(
                "Model [{$model}] must implement [{$contract}]."
            );
        }

        return static::$cache[$key] = $model;
    }

    public static function flushCache(): void
    {
        static::$cache = [];
    }
}

<?php

namespace JeffersonGoncalves\KnowledgeBase;

use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleFeedbackContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleRelationContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleVersionContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\CategoryContract;
use JeffersonGoncalves\KnowledgeBase\Services\KnowledgeBaseService;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class KnowledgeBaseServiceProvider extends PackageServiceProvider
{
    public static string $name = 'knowledge-base';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasMigrations([
                'create_kb_categories_table',
                'create_kb_articles_table',
                'create_kb_article_versions_table',
                'create_kb_article_feedback_table',
                'create_kb_article_relations_table',
            ]);
    }

    public function packageBooted(): void
    {
        $this->registerModelBindings();
        $this->registerServices();
    }

    protected function registerModelBindings(): void
    {
        $bindings = [
            ArticleContract::class => 'article',
            CategoryContract::class => 'category',
            ArticleVersionContract::class => 'article_version',
            ArticleFeedbackContract::class => 'article_feedback',
            ArticleRelationContract::class => 'article_relation',
        ];

        foreach ($bindings as $contract => $configKey) {
            $this->app->bind($contract, config("knowledge-base.models.{$configKey}"));
        }
    }

    protected function registerServices(): void
    {
        $this->app->singleton(KnowledgeBaseService::class);
    }
}

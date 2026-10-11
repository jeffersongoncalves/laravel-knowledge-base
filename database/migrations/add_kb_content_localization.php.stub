<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JeffersonGoncalves\KnowledgeBase\Support\Locales;

/**
 * Adds content localization (see design D1, D6 and D8).
 *
 * Creates the article and category translation tables, adds
 * `kb_articles.source_locale` and `kb_article_versions.locale`, then backfills
 * one version per existing article and category in the configured default
 * language. Existing content is tagged with that language, so set
 * `knowledge-base.default_locale` to the language existing content is written
 * in *before* running this migration.
 *
 * Every step is idempotent, so re-running it neither duplicates rows nor
 * overwrites versions that already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';
        // Throws on a default_locale outside `locales`, before anything is backfilled.
        $defaultLocale = Locales::default();

        $this->createArticleTranslationsTable($prefix);
        $this->createCategoryTranslationsTable($prefix);

        $this->addSourceLocaleColumn($prefix, $defaultLocale);
        $this->addVersionLocaleColumn($prefix);

        $this->backfillArticleTranslations($prefix, $defaultLocale);
        $this->backfillCategoryTranslations($prefix, $defaultLocale);
        $this->tagExistingVersions($prefix, $defaultLocale);
    }

    public function down(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        Schema::dropIfExists($prefix.'article_translations');
        Schema::dropIfExists($prefix.'category_translations');

        if (Schema::hasColumn($prefix.'articles', 'source_locale')) {
            Schema::table($prefix.'articles', function (Blueprint $table) {
                $table->dropColumn('source_locale');
            });
        }

        if (Schema::hasColumn($prefix.'article_versions', 'locale')) {
            Schema::table($prefix.'article_versions', function (Blueprint $table) {
                $table->dropColumn('locale');
            });
        }
    }

    protected function createArticleTranslationsTable(string $prefix): void
    {
        if (Schema::hasTable($prefix.'article_translations')) {
            return;
        }

        Schema::create($prefix.'article_translations', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('article_id')->constrained($prefix.'articles')->cascadeOnDelete();
            $table->string('locale', 16);
            $table->string('title');
            $table->string('slug');
            $table->longText('content');
            $table->text('excerpt')->nullable();
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('seo_keywords')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->boolean('is_outdated')->default(false)->index();
            $table->timestamp('outdated_at')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'locale']);
            $table->unique(['locale', 'slug']);

            if (DB::getDriverName() !== 'sqlite') {
                $table->fullText(['title', 'content']);
            }
        });
    }

    protected function createCategoryTranslationsTable(string $prefix): void
    {
        if (Schema::hasTable($prefix.'category_translations')) {
            return;
        }

        Schema::create($prefix.'category_translations', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('category_id')->constrained($prefix.'categories')->cascadeOnDelete();
            $table->string('locale', 16);
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'locale']);
            $table->unique(['locale', 'slug']);
        });
    }

    protected function addSourceLocaleColumn(string $prefix, string $defaultLocale): void
    {
        if (Schema::hasColumn($prefix.'articles', 'source_locale')) {
            return;
        }

        Schema::table($prefix.'articles', function (Blueprint $table) use ($defaultLocale) {
            $table->string('source_locale', 16)->default($defaultLocale);
        });
    }

    protected function addVersionLocaleColumn(string $prefix): void
    {
        if (Schema::hasColumn($prefix.'article_versions', 'locale')) {
            return;
        }

        Schema::table($prefix.'article_versions', function (Blueprint $table) {
            $table->string('locale', 16)->nullable();
        });
    }

    protected function backfillArticleTranslations(string $prefix, string $defaultLocale): void
    {
        DB::table($prefix.'articles')->orderBy('id')->chunkById(100, function ($articles) use ($prefix, $defaultLocale) {
            foreach ($articles as $article) {
                $exists = DB::table($prefix.'article_translations')
                    ->where('article_id', $article->id)
                    ->where('locale', $defaultLocale)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table($prefix.'article_translations')->insert([
                    'article_id' => $article->id,
                    'locale' => $defaultLocale,
                    'title' => $article->title,
                    'slug' => $article->slug,
                    'content' => $article->content,
                    'excerpt' => $article->excerpt,
                    'seo_title' => $article->seo_title,
                    'seo_description' => $article->seo_description,
                    'seo_keywords' => $article->seo_keywords,
                    // Versions are only draft or published; archived (or any
                    // other non-published) articles get a draft version, the
                    // same mapping the model mirror uses.
                    'status' => $article->status === 'published' ? 'published' : 'draft',
                    'published_at' => $article->published_at,
                    'is_outdated' => false,
                    'outdated_at' => null,
                    'created_at' => $article->created_at ?? now(),
                    'updated_at' => $article->updated_at ?? now(),
                ]);
            }
        });
    }

    protected function backfillCategoryTranslations(string $prefix, string $defaultLocale): void
    {
        DB::table($prefix.'categories')->orderBy('id')->chunkById(100, function ($categories) use ($prefix, $defaultLocale) {
            foreach ($categories as $category) {
                $exists = DB::table($prefix.'category_translations')
                    ->where('category_id', $category->id)
                    ->where('locale', $defaultLocale)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table($prefix.'category_translations')->insert([
                    'category_id' => $category->id,
                    'locale' => $defaultLocale,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'created_at' => $category->created_at ?? now(),
                    'updated_at' => $category->updated_at ?? now(),
                ]);
            }
        });
    }

    protected function tagExistingVersions(string $prefix, string $defaultLocale): void
    {
        DB::table($prefix.'article_versions')
            ->whereNull('locale')
            ->update(['locale' => $defaultLocale]);
    }
};

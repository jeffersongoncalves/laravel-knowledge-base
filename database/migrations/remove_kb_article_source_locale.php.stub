<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JeffersonGoncalves\KnowledgeBase\Support\Locales;

/**
 * Makes the configured default language authoritative for every article (see
 * design D1).
 *
 * Articles no longer choose their own authoritative language: it is always
 * `knowledge-base.default_locale`, as it already is for categories and
 * collections. Before `kb_articles.source_locale` is dropped, every article
 * whose stored value differs from the default is re-mirrored from its version
 * in the default language — the same rewrite `changeSourceLocale()` used to do,
 * including the promoted version's status. If any such article has no
 * default-language version the migration stops before changing anything and
 * names those articles, so they can be translated first.
 *
 * `down()` re-adds the column, filled with the configured default, even when
 * the current language configuration does not validate.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        if (! Schema::hasColumn($prefix.'articles', 'source_locale')) {
            return;
        }

        $defaultLocale = Locales::default();

        $this->promoteDefaultVersions($prefix, $defaultLocale);

        Schema::table($prefix.'articles', function (Blueprint $table) {
            $table->dropColumn('source_locale');
        });
    }

    public function down(): void
    {
        $prefix = config('knowledge-base.table_prefix') ?? '';

        if (Schema::hasColumn($prefix.'articles', 'source_locale')) {
            return;
        }

        // Read the raw value instead of Locales::default(): a rollback must not
        // fail because the current language configuration is invalid.
        $defaultLocale = config('knowledge-base.default_locale');
        $defaultLocale = is_string($defaultLocale) && $defaultLocale !== '' ? $defaultLocale : 'en';

        Schema::table($prefix.'articles', function (Blueprint $table) use ($defaultLocale) {
            $table->string('source_locale', 16)->default($defaultLocale);
        });
    }

    /**
     * Re-mirror every article whose stored source_locale is not the default
     * from its version in the default language.
     *
     * All articles are checked before the first row is written, so a missing
     * default-language version leaves the schema and data untouched.
     */
    protected function promoteDefaultVersions(string $prefix, string $defaultLocale): void
    {
        $articles = DB::table($prefix.'articles')
            ->where('source_locale', '!=', $defaultLocale)
            ->orderBy('id')
            ->get();

        if ($articles->isEmpty()) {
            return;
        }

        $versions = DB::table($prefix.'article_translations')
            ->where('locale', $defaultLocale)
            ->whereIn('article_id', $articles->pluck('id')->all())
            ->get()
            ->keyBy('article_id');

        $missing = $articles
            ->reject(fn ($article): bool => $versions->has($article->id))
            ->pluck('id')
            ->all();

        if ($missing !== []) {
            throw new RuntimeException(
                'Cannot remove kb_articles.source_locale: these articles have no version in ['
                .$defaultLocale.']: '.implode(', ', $missing).'. Add a default-language version before upgrading.'
            );
        }

        // All rewrites succeed or none do.
        DB::transaction(function () use ($articles, $versions, $prefix, $defaultLocale): void {
            foreach ($articles as $article) {
                $this->promote($prefix, $defaultLocale, $article, $versions->get($article->id));
            }
        });
    }

    /**
     * Mirror one article's default-language version onto its own row.
     */
    protected function promote(string $prefix, string $defaultLocale, object $article, object $version): void
    {
        DB::table($prefix.'articles')->where('id', $article->id)->update([
            'title' => $version->title,
            'slug' => $version->slug,
            'content' => $version->content,
            'excerpt' => $version->excerpt,
            'seo_title' => $version->seo_title,
            'seo_description' => $version->seo_description,
            'seo_keywords' => $version->seo_keywords,
            'source_locale' => $defaultLocale,
            'updated_at' => now(),
        ]);

        // The promoted (default-language) version becomes the authoritative
        // one, so it follows the article's status from now on.
        DB::table($prefix.'article_translations')
            ->where('id', $version->id)
            ->update([
                'status' => $article->status === 'published' ? 'published' : 'draft',
                'updated_at' => now(),
            ]);
    }
};

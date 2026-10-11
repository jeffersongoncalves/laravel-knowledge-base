<?php

namespace JeffersonGoncalves\KnowledgeBase\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\KnowledgeBase\Support\ArticleAssets;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;

/**
 * Removes files under the assets directory that no asset row knows, and folders
 * whose article no longer exists at all (see design D8). A soft-deleted article
 * keeps its folder; unused but valid assets are never pruned. Reports asset
 * rows whose file is missing. `--dry-run` lists without deleting.
 */
class PruneAssetsCommand extends Command
{
    protected $signature = 'kb:prune-assets {--dry-run : List what would be removed without removing it}';

    protected $description = 'Remove stray asset files and folders of deleted articles';

    public function handle(ArticleAssets $assets): int
    {
        $disk = $assets->disk();
        $storage = Storage::disk($disk);
        $root = $assets->directory().'/articles';
        $dryRun = (bool) $this->option('dry-run');

        $assetClass = ModelResolver::articleAsset();
        $knownPaths = $assetClass::query()->pluck('path')->map(fn ($path): string => (string) $path)->all();
        $knownUuids = $this->knownArticleUuids();

        $removed = 0;
        $removed += $this->removeStrayFiles($storage, $root, $knownPaths, $dryRun);
        $removed += $this->removeDeletedArticleFolders($storage, $root, $knownUuids, $dryRun);

        $this->reportMissingFiles();

        if ($removed === 0) {
            $this->info('Nothing to prune.');
        } else {
            $this->info(($dryRun ? 'Would remove ' : 'Removed ').$removed.' item(s).');
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $knownPaths
     */
    protected function removeStrayFiles(Filesystem $storage, string $root, array $knownPaths, bool $dryRun): int
    {
        $removed = 0;

        foreach ($storage->allFiles($root) as $file) {
            if (in_array($file, $knownPaths, true)) {
                continue;
            }

            $this->line("Stray file: {$file}");

            if (! $dryRun) {
                $storage->delete($file);
            }

            $removed++;
        }

        return $removed;
    }

    /**
     * @param  list<string>  $knownUuids
     */
    protected function removeDeletedArticleFolders(Filesystem $storage, string $root, array $knownUuids, bool $dryRun): int
    {
        $removed = 0;

        foreach ($storage->directories($root) as $folder) {
            if (in_array(basename($folder), $knownUuids, true)) {
                continue;
            }

            $this->line("Folder of a deleted article: {$folder}");

            if (! $dryRun) {
                $storage->deleteDirectory($folder);
            }

            $removed++;
        }

        return $removed;
    }

    /**
     * Report asset rows whose file is missing, without deleting the row.
     */
    protected function reportMissingFiles(): void
    {
        $assetClass = ModelResolver::articleAsset();

        foreach ($assetClass::query()->get() as $asset) {
            $disk = (string) $asset->getAttribute('disk');
            $path = (string) $asset->getAttribute('path');

            if (! Storage::disk($disk)->exists($path)) {
                $this->warn("Missing file for asset {$asset->getAttribute('uuid')}: {$disk}:{$path}");
            }
        }
    }

    /**
     * The uuids of every article, soft-deleted ones included: a soft-deleted
     * article keeps its folder until it is force-deleted.
     *
     * @return list<string>
     */
    protected function knownArticleUuids(): array
    {
        $articleClass = ModelResolver::article();

        /** @var Builder<Model> $query */
        $query = $articleClass::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($articleClass), true)) {
            // @phpstan-ignore method.notFound (withTrashed() is provided by the SoftDeletes trait)
            $query->withTrashed();
        }

        return $query->pluck('uuid')->map(fn ($uuid): string => (string) $uuid)->all();
    }
}

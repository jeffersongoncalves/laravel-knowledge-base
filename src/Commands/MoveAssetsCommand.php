<?php

namespace JeffersonGoncalves\KnowledgeBase\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use JeffersonGoncalves\KnowledgeBase\Support\ArticleAssets;
use JeffersonGoncalves\KnowledgeBase\Support\ModelResolver;
use RuntimeException;
use Throwable;

/**
 * Moves every asset that is stored on another disk to the configured one,
 * keeping its path (see the serve-private-assets design D5). Used once after
 * switching from the public disk to a private one. Each file is copied, the
 * record updated, and only then the old file removed, so an interrupted run
 * leaves every asset working. `--dry-run` lists without moving.
 */
class MoveAssetsCommand extends Command
{
    protected $signature = 'kb:move-assets {--dry-run : List what would be moved without moving it}';

    protected $description = 'Move article assets to the configured disk';

    public function handle(ArticleAssets $assets): int
    {
        $target = $assets->disk();
        $dryRun = (bool) $this->option('dry-run');

        $assetClass = ModelResolver::articleAsset();
        $moved = 0;
        $failed = 0;
        $sourceFolders = [];

        foreach ($assetClass::query()->where('disk', '!=', $target)->lazyById() as $asset) {
            /** @var Model $asset */
            $source = (string) $asset->getAttribute('disk');
            $path = (string) $asset->getAttribute('path');

            if ($dryRun) {
                $this->line("{$source}:{$path} → {$target}:{$path}");
                $moved++;

                continue;
            }

            try {
                $this->moveFile($source, $target, $path);
            } catch (Throwable $exception) {
                $this->error("Could not move {$source}:{$path}: {$exception->getMessage()}");
                $failed++;

                continue;
            }

            // Without firing model events: the record keeps its file, which now
            // lives on the target disk.
            $asset->forceFill(['disk' => $target])->saveQuietly();
            Storage::disk($source)->delete($path);

            $sourceFolders[$source][dirname($path)] = true;
            $moved++;
        }

        if (! $dryRun) {
            $this->removeEmptyFolders($sourceFolders);
        }

        if ($moved === 0 && $failed === 0) {
            $this->info("Every asset is already on the '{$target}' disk.");
        } else {
            $this->info(($dryRun ? 'Would move ' : 'Moved ')."{$moved} asset(s) to the '{$target}' disk.");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function moveFile(string $source, string $target, string $path): void
    {
        $from = Storage::disk($source);
        $to = Storage::disk($target);

        if (! $from->exists($path)) {
            // Already moved by an interrupted run: only the record is left.
            if ($to->exists($path)) {
                return;
            }

            throw new RuntimeException('the file is missing');
        }

        $stream = $from->readStream($path);

        if ($stream === null) {
            throw new RuntimeException('the file cannot be read');
        }

        try {
            if ($to->writeStream($path, $stream) === false) {
                throw new RuntimeException('the target disk refused the file');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Remove the language and article folders the moved files leave empty.
     *
     * @param  array<string, array<string, true>>  $folders
     */
    protected function removeEmptyFolders(array $folders): void
    {
        foreach ($folders as $disk => $paths) {
            $storage = Storage::disk($disk);

            foreach (array_keys($paths) as $folder) {
                foreach ([$folder, dirname($folder)] as $candidate) {
                    if ($storage->directoryExists($candidate)
                        && $storage->allFiles($candidate) === []
                        && $storage->allDirectories($candidate) === []) {
                        $storage->deleteDirectory($candidate);
                    }
                }
            }
        }
    }
}

<?php

namespace JeffersonGoncalves\KnowledgeBase\Models\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use JeffersonGoncalves\KnowledgeBase\Enums\AssetKind;

/**
 * An image or file that belongs to an article (see design D2).
 */
interface ArticleAssetContract
{
    public function article(): BelongsTo;

    public function kind(): AssetKind;

    /**
     * Whether the asset is shared by every language version of the article
     * (as opposed to limited to one non-default language).
     */
    public function isShared(): bool;
}

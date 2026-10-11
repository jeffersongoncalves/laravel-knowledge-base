<?php

namespace JeffersonGoncalves\KnowledgeBase\Enums;

enum AssetKind: string
{
    case Image = 'image';

    case File = 'file';

    public function label(): string
    {
        return __('knowledge-base::knowledge-base.asset_kind.'.$this->value);
    }
}

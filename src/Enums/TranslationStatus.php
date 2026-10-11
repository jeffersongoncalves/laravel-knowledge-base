<?php

namespace JeffersonGoncalves\KnowledgeBase\Enums;

enum TranslationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return __('knowledge-base::knowledge-base.translation_status.'.$this->value);
    }
}

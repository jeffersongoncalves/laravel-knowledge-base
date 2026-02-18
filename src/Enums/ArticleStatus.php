<?php

namespace JeffersonGoncalves\KnowledgeBase\Enums;

enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return __('knowledge-base::knowledge-base.article_status.'.$this->value);
    }
}

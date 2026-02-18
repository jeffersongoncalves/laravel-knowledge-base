<?php

namespace JeffersonGoncalves\KnowledgeBase\Enums;

enum ArticleVisibility: string
{
    case Public = 'public';
    case Internal = 'internal';

    public function label(): string
    {
        return __('knowledge-base::knowledge-base.visibility.'.$this->value);
    }
}

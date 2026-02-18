<?php

declare(strict_types=1);

namespace JeffersonGoncalves\KnowledgeBase\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleContract;
use JeffersonGoncalves\KnowledgeBase\Models\Contracts\ArticleFeedbackContract;

class ArticleFeedbackReceived
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Model&ArticleContract $article,
        public readonly Model&ArticleFeedbackContract $feedback,
    ) {}
}

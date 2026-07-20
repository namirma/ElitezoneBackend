<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\BaseJsonResource;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Article
 */
class ArticleResource extends BaseJsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            Article::ID => $this->id,
            Article::TITLE => $this->title,
            Article::SLUG => $this->slug,
            Article::EXCERPT => $this->excerpt,
            Article::BODY => $this->body,
            Article::COVER => $this->cover
                ? (str_starts_with($this->cover, 'http') ? $this->cover : Storage::disk('public')->url($this->cover))
                : null,
            Article::AUTHOR_ID => $this->author_id,
            Article::IS_ACTIVE => $this->is_active,
            Article::PUBLISHED_AT => $this->published_at,
            Article::CREATED_AT => $this->created_at,
            'created_at_p' => $this->created_at_p,
        ];
    }
}

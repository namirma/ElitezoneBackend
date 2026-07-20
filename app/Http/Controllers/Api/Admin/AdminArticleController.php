<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreArticleRequest;
use App\Http\Requests\Admin\UpdateArticleRequest;
use App\Http\Resources\Content\ArticleResource;
use App\Models\Article;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * پنل مدیریت — CRUD مقالات (ماژول ۲ نیازمندی فرانت BackOffice).
 *
 * کاور روی دیسک public ذخیره می‌شود؛ آپلود/تعویض همیشه با POST/multipart.
 */
class AdminArticleController extends Controller
{
    // GET /admin/articles
    public function index(Request $request): JsonResponse
    {
        $query = Article::query()->orderByDesc(Article::ID);

        if ($request->has('is_active')) {
            $query->where(Article::IS_ACTIVE, $request->boolean('is_active'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search');
            $query->where(fn ($q) => $q
                ->where(Article::TITLE, 'like', "%{$search}%")
                ->orWhere(Article::SLUG, 'like', "%{$search}%"));
        }

        // سطل بازیافت: ?trashed=1 فقط حذف‌شده‌های نرم را برمی‌گرداند
        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        return success(ArticleResource::collection($query->paginate($this->perPage())), __('admin.articles.list_received'));
    }

    // GET /admin/articles/{article}
    public function show(Article $article): JsonResponse
    {
        return success(new ArticleResource($article), __('admin.articles.received'));
    }

    // POST /admin/articles
    public function store(StoreArticleRequest $request): JsonResponse
    {
        $data = $request->safe()->except(Article::COVER);
        $data[Article::AUTHOR_ID] = Auth::id();

        if ($request->hasFile(Article::COVER)) {
            $data[Article::COVER] = $request->file(Article::COVER)->store('articles', 'public');
        }

        $article = Article::create($data);

        return created(new ArticleResource($article), __('admin.articles.created'));
    }

    // PUT|POST /admin/articles/{article}
    public function update(UpdateArticleRequest $request, Article $article): JsonResponse
    {
        $data = $request->safe()->except(Article::COVER);

        if ($request->hasFile(Article::COVER)) {
            $this->deleteCoverIfOwned($article);
            $data[Article::COVER] = $request->file(Article::COVER)->store('articles', 'public');
        }

        $article->update($data);

        return success(new ArticleResource($article->refresh()), __('admin.articles.updated'));
    }

    // POST /admin/articles/{article}/toggle
    public function toggle(Article $article): JsonResponse
    {
        $article->update([Article::IS_ACTIVE => ! $article->{Article::IS_ACTIVE}]);

        return success(new ArticleResource($article->refresh()), __('admin.articles.toggled'));
    }

    // DELETE /admin/articles/{article} — حذف نرم (کاور تا restore حفظ می‌شود)
    public function destroy(Article $article): JsonResponse
    {
        $article->delete();

        return success(null, __('admin.articles.deleted'));
    }

    // POST /admin/articles/{id}/restore
    public function restore(int $id): JsonResponse
    {
        $article = Article::onlyTrashed()->findOrFail($id);
        $article->restore();

        return success(new ArticleResource($article->refresh()), __('admin.articles.restored'));
    }

    private function deleteCoverIfOwned(Article $article): void
    {
        $cover = $article->{Article::COVER};

        if ($cover && ! str_starts_with($cover, 'http') && Storage::disk('public')->exists($cover)) {
            Storage::disk('public')->delete($cover);
        }
    }
}

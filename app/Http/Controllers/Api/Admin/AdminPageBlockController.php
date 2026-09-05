<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePageBlockRequest;
use App\Http\Requests\Admin\UpdatePageBlockRequest;
use App\Http\Resources\Content\PageBlockResource;
use App\Models\PageBlock;
use App\Services\Content\PageBlockSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPageBlockController extends Controller
{
    public function schema(PageBlockSchema $schema): JsonResponse
    {
        return success($schema->all(), __('page_blocks.schema_received'));
    }

    public function index(Request $request): JsonResponse
    {
        $query = PageBlock::query()
            ->orderBy(PageBlock::SORT_ORDER)
            ->orderBy(PageBlock::ID);

        if ($request->filled('page')) {
            $query->where(PageBlock::PAGE, $request->string('page'));
        }

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        return success(PageBlockResource::collection($query->get()), __('page_blocks.list_received'));
    }

    public function show(PageBlock $pageBlock): JsonResponse
    {
        return success(new PageBlockResource($pageBlock), __('page_blocks.received'));
    }

    public function store(StorePageBlockRequest $request): JsonResponse
    {
        $block = PageBlock::create($request->validated());

        return created(new PageBlockResource($block), __('page_blocks.created'));
    }

    public function update(UpdatePageBlockRequest $request, PageBlock $pageBlock): JsonResponse
    {
        $pageBlock->update($request->validated());

        return success(new PageBlockResource($pageBlock->fresh()), __('page_blocks.updated'));
    }

    public function destroy(PageBlock $pageBlock): JsonResponse
    {
        $pageBlock->delete();

        return success(null, __('page_blocks.deleted'));
    }
}

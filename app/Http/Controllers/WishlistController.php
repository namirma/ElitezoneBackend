<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWishlistRequest;
use App\Http\Requests\UpdateWishlistRequest;
use App\Http\Resources\WishlistResource;
use App\Models\ProductVariant;
use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WishlistController extends Controller
{
    private function findOrCreateDefaultWishlist()
    {
        return Auth::user()->wishlists()->firstOrCreate(
            [Wishlist::IS_DEFAULT => true],
            [Wishlist::NAME => 'علاقه‌مندی‌ها']
        );
    }

    public function index(): JsonResponse
    {
        $this->findOrCreateDefaultWishlist();
        $wishlists = Auth::user()->wishlists()->withCount('variants')->latest()->get();
        return success(WishlistResource::collection($wishlists), 'wishlist.index.success');
    }

    public function store(StoreWishlistRequest $request): JsonResponse
    {
        $wishlist = Auth::user()->wishlists()->create($request->validated());
        return success(new WishlistResource($wishlist), 'wishlist.store.success', 201);
    }

    public function show(Wishlist $wishlist): JsonResponse
    {
        if (Auth::id() !== $wishlist->{Wishlist::USER_ID}) {
            return error('global.unauthorized', [], 403);
        }
        $variants = $wishlist->variants()->with('product:id,name,slug')->latest()->paginate(10);
        $wishlist->setRelation('variants', $variants);
        return success(new WishlistResource($wishlist), 'wishlist.show.success');
    }

    public function update(UpdateWishlistRequest $request, Wishlist $wishlist): JsonResponse
    {
        if (Auth::id() !== $wishlist->{Wishlist::USER_ID}) {
            return error('global.unauthorized', [], 403);
        }
        if ($wishlist->{Wishlist::IS_DEFAULT}) {
            return error('wishlist.update.default_denied', [], 403);
        }
        $wishlist->update($request->validated());
        return success(new WishlistResource($wishlist), 'wishlist.update.success');
    }

    public function destroy(Wishlist $wishlist): JsonResponse
    {
        if (Auth::id() !== $wishlist->{Wishlist::USER_ID}) {
            return error('global.unauthorized', [], 403);
        }
        if ($wishlist->{Wishlist::IS_DEFAULT}) {
            return error('wishlist.destroy.default_denied', [], 403);
        }
        $wishlist->delete();
        return success([], 'wishlist.destroy.success');
    }

    public function addVariant(Wishlist $wishlist, ProductVariant $variant): JsonResponse
    {
        if (Auth::id() !== $wishlist->{Wishlist::USER_ID}) {
            return error('global.unauthorized', [], 403);
        }
        if ($wishlist->variants()->where('product_variant_id', $variant->id)->exists()) {
            return error('wishlist.variants.store.duplicate', [], 409);
        }
        $wishlist->variants()->attach($variant->id);
        return success([], 'wishlist.variants.store.success');
    }

    public function removeVariant(Wishlist $wishlist, ProductVariant $variant): JsonResponse
    {
        if (Auth::id() !== $wishlist->{Wishlist::USER_ID}) {
            return error('global.unauthorized', [], 403);
        }
        if (!$wishlist->variants()->where('product_variant_id', $variant->id)->exists()) {
            return error('wishlist.variants.destroy.not_found', [], 404);
        }
        $wishlist->variants()->detach($variant->id);
        return success([], 'wishlist.variants.destroy.success');
    }

    public function toggleVariant(Request $request): JsonResponse
    {
        $validatedData = $request->validate(['variant_id' => ['required', 'integer', 'exists:product_variants,id']]);
        $variantId = $validatedData['variant_id'];
        $defaultWishlist = $this->findOrCreateDefaultWishlist();
        $toggleResult = $defaultWishlist->variants()->toggle($variantId);
        $isAttached = count($toggleResult['attached']) > 0;
        return success(['is_in_wishlist' => $isAttached], 'wishlist.toggle.success');
    }
}

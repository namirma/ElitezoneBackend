<?php

namespace App\Http\Resources\Supplier;

use App\Http\Resources\BaseJsonResource;
use App\Models\Factory;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * @mixin Factory
 */
class FactoryResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        // api_key/api_url are integration credentials (Espadana) — only the owning
        // supplier or an admin may see them. This resource also backs the PUBLIC
        // GET /factories listing, which must never leak them.
        $viewer = $request->user();
        $canSeeCredentials = $viewer && ($viewer->hasRole('admin') || (int) $viewer->id === (int) $this->supplier_user_id);

        return [
            Factory::ID => $this->id,
            Factory::NAME => $this->name,
            Factory::SLUG => $this->slug,
            Factory::DESCRIPTION => $this->description,
            Factory::LOGO => $this->logo,
            Factory::API_KEY => $canSeeCredentials ? $this->api_key : null,
            Factory::API_URL => $canSeeCredentials ? $this->api_url : null,
            Factory::IS_ACTIVE => (bool) $this->is_active,
            Factory::CREATED_AT => $this->created_at ? Carbon::parse($this->created_at)->format('Y-m-d H:i:s') : null,
            Factory::UPDATED_AT => $this->updated_at ? Carbon::parse($this->updated_at)->format('Y-m-d H:i:s') : null,

            'locations' => FactoryLocationResource::collection($this->whenLoaded('locations')),
            // دو کلیدِ جدا — مصرف‌کننده‌ها عمداً نمی‌توانند اشتباهی دسته‌ی دیگر را نشان دهند.
            'product_features' => FactoryFeatureResource::collection($this->whenLoaded('activeProductFeatures')),
            'shipping_features' => FactoryFeatureResource::collection($this->whenLoaded('activeShippingFeatures')),
        ];
    }
}

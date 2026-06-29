<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\AttributeValueResource;
use App\Models\AttributeValue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * پنل مدیریت — CRUD مقادیر ویژگی (AttributeValue).
 *
 * هر مقدار متعلق به یک Attribute است (مثلاً مقدار «۱۲» برای ویژگی «قطر»).
 * مقداری که توسط واریانت محصول استفاده شده، قابل حذف نیست.
 */
class AdminAttributeValueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'attribute_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $values = AttributeValue::query()
            ->when(
                $request->filled('attribute_id'),
                fn ($q) => $q->where('attribute_id', $request->integer('attribute_id'))
            )
            ->orderBy('attribute_id')
            ->orderBy('id')
            ->paginate($this->perPage(200));

        return success(AttributeValueResource::collection($values), __('admin.attribute_values.list_received'));
    }

    public function store(Request $request): JsonResponse
    {
        $value = AttributeValue::create($request->validate($this->rules()));

        return created(new AttributeValueResource($value), __('admin.attribute_values.created'));
    }

    public function update(Request $request, AttributeValue $attributeValue): JsonResponse
    {
        $attributeValue->update($request->validate($this->rules(true)));

        return success(new AttributeValueResource($attributeValue), __('admin.attribute_values.updated'));
    }

    public function destroy(AttributeValue $attributeValue): JsonResponse
    {
        if ($attributeValue->productVariants()->exists()) {
            return error(__('admin.attribute_values.in_use'), [], 422);
        }

        $attributeValue->delete();

        return success(null, __('admin.attribute_values.deleted'));
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'attribute_id' => [$req, 'integer', 'exists:attributes,id'],
            'value' => [$req, 'string', 'max:255'],
            'display_value' => [$req, 'string', 'max:255'],
        ];
    }
}

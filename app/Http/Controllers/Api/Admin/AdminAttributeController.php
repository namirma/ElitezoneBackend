<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\AttributeResource;
use App\Models\Attribute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * پنل مدیریت — CRUD ویژگی‌ها (Attribute).
 *
 * هر ویژگی متعلق به یک AttributeType است و چند AttributeValue دارد.
 * حذف ویژگی، مقادیر آن را به صورت cascade پاک می‌کند (طبق onDelete دیتابیس).
 */
class AdminAttributeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'attribute_type_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $attributes = Attribute::query()
            ->when(
                $request->filled('attribute_type_id'),
                fn ($q) => $q->where(Attribute::ATTRIBUTE_TYPE_ID, $request->integer('attribute_type_id'))
            )
            ->with('values')
            ->orderBy(Attribute::NAME)
            ->paginate($this->perPage(100));

        return success(AttributeResource::collection($attributes), __('admin.attributes.list_received'));
    }

    public function store(Request $request): JsonResponse
    {
        $attribute = Attribute::create($request->validate($this->rules()));

        return created(new AttributeResource($attribute->load('values')), __('admin.attributes.created'));
    }

    public function update(Request $request, Attribute $attribute): JsonResponse
    {
        $attribute->update($request->validate($this->rules(true)));

        return success(new AttributeResource($attribute->load('values')), __('admin.attributes.updated'));
    }

    public function destroy(Attribute $attribute): JsonResponse
    {
        $attribute->delete();

        return success(null, __('admin.attributes.deleted'));
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            Attribute::ATTRIBUTE_TYPE_ID => [$req, 'integer', 'exists:attribute_types,id'],
            Attribute::NAME => [$req, 'string', 'max:255'],
            Attribute::DISPLAY_NAME => [$req, 'string', 'max:255'],
        ];
    }
}

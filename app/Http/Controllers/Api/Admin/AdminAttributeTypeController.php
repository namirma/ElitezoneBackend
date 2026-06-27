<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Product\AttributeTypeResource;
use App\Models\Attribute;
use App\Models\AttributeType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * پنل مدیریت — CRUD نوع ویژگی (AttributeType).
 *
 * نوع ویژگی پایه‌ی ویژگی‌ها و در نهایت واریانت محصول است (مثلاً «قطر»، «رنگ»).
 * input_type نوع ورودی فرانت را مشخص می‌کند: select / text / color / number.
 */
class AdminAttributeTypeController extends Controller
{
    private const INPUT_TYPES = ['select', 'text', 'color', 'number'];

    public function index(): JsonResponse
    {
        $types = AttributeType::orderBy(AttributeType::NAME)->paginate($this->perPage(100));

        return success(AttributeTypeResource::collection($types), __('admin.attribute_types.list_received'));
    }

    public function store(Request $request): JsonResponse
    {
        $type = AttributeType::create($request->validate($this->rules()));

        return created(new AttributeTypeResource($type), __('admin.attribute_types.created'));
    }

    public function update(Request $request, AttributeType $attributeType): JsonResponse
    {
        $attributeType->update($request->validate($this->rules(true)));

        return success(new AttributeTypeResource($attributeType), __('admin.attribute_types.updated'));
    }

    public function destroy(AttributeType $attributeType): JsonResponse
    {
        if (Attribute::where(Attribute::ATTRIBUTE_TYPE_ID, $attributeType->id)->exists()) {
            return error(__('admin.attribute_types.has_attributes'), [], 422);
        }

        $attributeType->delete();

        return success(null, __('admin.attribute_types.deleted'));
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            AttributeType::NAME => [$req, 'string', 'max:255'],
            AttributeType::DISPLAY_NAME => [$req, 'string', 'max:255'],
            AttributeType::INPUT_TYPE => [$req, Rule::in(self::INPUT_TYPES)],
        ];
    }
}

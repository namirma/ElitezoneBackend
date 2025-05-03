<?php
namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\Attribute;

class AttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            Attribute::ID              => $this->id,
            Attribute::ATTRIBUTE_TYPE_ID => $this->attribute_type_id,
            Attribute::NAME            => $this->name,
            Attribute::DISPLAY_NAME    => $this->display_name,
        ];
    }
}

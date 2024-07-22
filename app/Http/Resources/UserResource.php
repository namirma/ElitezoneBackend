<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            User::TYPE_ID => $this->type_id,
            User::MOBILE => $this->mobile,
            User::CREATED_AT => $this->created_at_,
            User::PASSWORD => (bool)$this->password,
            'created_at_p' => $this->created_at_p,
            'created_at_diff' => $this->created_at_diff,
            'profile' => $this->whenNotNull(new UserProfileResource($this->whenLoaded('profile'))),
            'engineer' => $this->whenNotNull(new EngineerProfileResourse($this->whenLoaded('engineer'))),
            'contractor' => $this->whenNotNull(new ContractorProfileResource($this->whenLoaded('contractor'))),
            'supplier' => $this->whenNotNull(new SupplierProfileResource($this->whenLoaded('supplier'))),
            'employer' => $this->whenNotNull(new EmployerProfileResource($this->whenLoaded('employer'))),
            'type' => $this->whenNotNull(new UserTypeResource($this->whenLoaded('type'))),
        ];
    }
}

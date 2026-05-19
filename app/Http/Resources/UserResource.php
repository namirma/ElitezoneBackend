<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $selectedBadge = null;
        if ($this->relationLoaded(User::RELATION_BADGE) && $this->{User::RELATION_BADGE}) {
            $selectedBadge = $this->{User::RELATION_BADGE};
        } elseif ($this->relationLoaded('badges') && $this->badges->isNotEmpty()) {
            $selectedBadge = $this->badges->last();
        }

        return [
            User::ID => $this->{User::ID},
            User::TYPE_ID => $this->{User::TYPE_ID},
            User::MOBILE => $this->{User::MOBILE},
            User::CREATED_AT => $this->{User::CREATED_AT},
            User::PASSWORD => (bool) $this->{User::PASSWORD},
            User::RELATION_PROFILE => new UserProfileResource($this->whenLoaded(User::RELATION_PROFILE)),
            User::RELATION_ENGINEER => new EngineerProfileResourse($this->whenLoaded(User::RELATION_ENGINEER)),
            User::RELATION_CONTRACTOR => new ContractorProfileResource($this->whenLoaded(User::RELATION_CONTRACTOR)),
            User::RELATION_SUPPLIER => new SupplierProfileResource($this->whenLoaded(User::RELATION_SUPPLIER)),
            User::RELATION_EMPLOYER => new EmployerProfileResource($this->whenLoaded(User::RELATION_EMPLOYER)),
            User::RELATION_TYPE => new UserTypeResource($this->whenLoaded(User::RELATION_TYPE)),
            User::RELATION_ROLES => $this->whenLoaded(User::RELATION_ROLES, function () {
                return $this->roles->pluck('name');
            }),
            User::RELATION_BADGE => $selectedBadge ? new BadgeResource($selectedBadge) : null,
            User::RELATION_EDUIX_RECORDS => EduixRecordResource::collection($this->whenLoaded(User::RELATION_EDUIX_RECORDS)),
            User::RELATION_JOB_EXPERIENCES => JobExperienceResource::collection($this->whenLoaded(User::RELATION_JOB_EXPERIENCES)),
            'created_at_p' => $this->created_at_p,
            'created_at_diff' => $this->created_at_diff,
        ];
    }
}

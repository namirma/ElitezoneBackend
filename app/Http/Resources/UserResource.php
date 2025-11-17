<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\BadgeResource;
use App\Http\Resources\EduixRecordResource;
use App\Http\Resources\JobExperienceResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            User::TYPE_ID => $this->{User::TYPE_ID},
            User::MOBILE => $this->{User::MOBILE},
            User::CREATED_AT => $this->{User::CREATED_AT}, // 🚀 اصلاح شد: (created_at_ -> created_at)
            User::PASSWORD => (bool)$this->{User::PASSWORD},
            'created_at_p' => $this->created_at_p,
            'created_at_diff' => $this->created_at_diff,

            User::RELATION_PROFILE => $this->whenNotNull(new UserProfileResource($this->whenLoaded(User::RELATION_PROFILE))),
            User::RELATION_ENGINEER => $this->whenNotNull(new EngineerProfileResourse($this->whenLoaded(User::RELATION_ENGINEER))),
            User::RELATION_CONTRACTOR => $this->whenNotNull(new ContractorProfileResource($this->whenLoaded(User::RELATION_CONTRACTOR))),
            User::RELATION_SUPPLIER => $this->whenNotNull(new SupplierProfileResource($this->whenLoaded(User::RELATION_SUPPLIER))),
            User::RELATION_EMPLOYER => $this->whenNotNull(new EmployerProfileResource($this->whenLoaded(User::RELATION_EMPLOYER))),

            User::RELATION_TYPE => $this->whenNotNull(new UserTypeResource($this->whenLoaded(User::RELATION_TYPE))),
            User::RELATION_ROLES => $this->whenLoaded(User::RELATION_ROLES, function () {
                return $this->roles->pluck('name');
            }),

            User::RELATION_BADGE => $this->whenNotNull(new BadgeResource($this->whenLoaded(User::RELATION_BADGE))),
            User::RELATION_EDUIX_RECORDS => EduixRecordResource::collection($this->whenLoaded(User::RELATION_EDUIX_RECORDS)),
            User::RELATION_JOB_EXPERIENCES => JobExperienceResource::collection($this->whenLoaded(User::RELATION_JOB_EXPERIENCES)),
        ];
    }
}

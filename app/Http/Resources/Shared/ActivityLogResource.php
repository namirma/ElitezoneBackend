<?php

namespace App\Http\Resources\Shared;

use Illuminate\Http\Request;
use App\Http\Resources\BaseJsonResource;

class ActivityLogResource extends BaseJsonResource
{
    public function toArray(Request $request): array
    {
        $causer = 'سیستم';
        if ($this->causer) {
            $profile = $this->causer->profile;
            $causer = $profile ? ("{$profile->name} {$profile->family}") : $this->causer->mobile;
        }

        return [
            'id' => $this->id,
            'description' => $this->description,
            'subject_type' => class_basename($this->subject_type),
            'subject_id' => $this->subject_id,
            'causer' => $causer,
            'ip_address' => $this->properties['ip'] ?? null,
            'changes' => $this->properties['attributes'] ?? [],
            'old' => $this->properties['old'] ?? [],
            'custom_props' => collect($this->properties)->except(['attributes', 'old', 'ip']),
            'created_at' => verta($this->created_at)->format('Y/m/d H:i:s'),
            'created_at_diff' => $this->created_at->diffForHumans(),
        ];
    }
}

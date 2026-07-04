<?php

namespace App\Models;

use Laratrust\Models\Permission as PermissionModel;

class Permission extends PermissionModel
{
    // Mass assignment is restricted by the parent's $fillable
    // (name, display_name, description) — do not add $guarded = [] here,
    // it would only be misleading (Eloquent's fillableFromArray() already
    // ignores $guarded whenever an inherited $fillable is non-empty).
}

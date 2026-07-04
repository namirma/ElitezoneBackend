<?php

namespace App\Models;

use Laratrust\Models\Role as RoleModel;

/**
 * @method static where(string $string, $type_id)
 */
class Role extends RoleModel
{
    // Mass assignment is restricted by the parent's $fillable
    // (name, display_name, description) — do not add $guarded = [] here,
    // it would only be misleading (Eloquent's fillableFromArray() already
    // ignores $guarded whenever an inherited $fillable is non-empty).
}

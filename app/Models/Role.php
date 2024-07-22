<?php

namespace App\Models;

use Laratrust\Models\Role as RoleModel;

/**
 * @method static where(string $string, $type_id)
 */
class Role extends RoleModel
{
    public $guarded = [];
}

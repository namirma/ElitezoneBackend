<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $province_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActivityProvince whereUserId($value)
 *
 * @mixin \Eloquent
 */
class ActivityProvince extends Model
{
    use HasFactory;
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $province_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince query()
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ActivityProvince whereUserId($value)
 * @mixin \Eloquent
 */
class ActivityProvince extends Model
{
    use HasFactory;
}

<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CouponTemplate extends Model { protected $table='v2_coupon_template'; protected $dateFormat='U'; protected $guarded=['id']; protected $casts=['plan_ids'=>'array','periods'=>'array']; }

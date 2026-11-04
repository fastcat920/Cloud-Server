<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CouponDistributionTask extends Model { protected $table='v2_coupon_distribution_task'; protected $dateFormat='U'; protected $guarded=['id']; protected $casts=['filters'=>'array','failed_user_ids'=>'array']; }

<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CouponTemplate;
use App\Models\FlashSaleCampaign;
use App\Models\Plan;
use App\Models\ReferralSetting;
use Illuminate\Http\Request;

class MarketingActivityController extends Controller
{
    public function index(Request $request)
    {
        $type = $request->input('type'); $status = $request->input('status'); $keyword = trim((string)$request->input('keyword'));
        $rows = collect();
        if (!$type || $type === 'flash_sale') $rows = $rows->concat(FlashSaleCampaign::all()->map(function ($x) { return $this->row($x, 'flash_sale'); }));
        if (!$type || $type === 'coupon') $rows = $rows->concat(CouponTemplate::all()->map(function ($x) { return $this->couponRow($x); }));
        if (!$type || $type === 'newcomer') {
            $setting = ReferralSetting::current();
            if ($setting->newcomer_coupon_template_id) {
                $coupon = CouponTemplate::find($setting->newcomer_coupon_template_id);
                $rows->push(['key'=>'newcomer:'.$setting->id,'source_id'=>$setting->id,'type'=>'newcomer','name'=>'新人专属奖励','name_en'=>'Newcomer reward','starts_at'=>$coupon ? $coupon->starts_at : null,'ends_at'=>$coupon ? $coupon->ends_at : null,'status'=>$setting->enabled?'active':'disabled','enabled'=>(bool)$setting->enabled,'scope'=>'受邀注册用户','orders'=>0,'revenue'=>0,'discount_total'=>0]);
            }
        }
        if ($keyword !== '') $rows = $rows->filter(function ($x) use ($keyword) { return stripos(($x['name'] ?? '').' '.($x['name_en'] ?? ''), $keyword) !== false; });
        if ($status) $rows = $rows->where('status', $status);
        $rows = $rows->sortByDesc(function ($x) { return $x['starts_at'] ?: 0; })->values();
        return response(['data'=>$rows,'plans'=>Plan::orderBy('id')->get(['id','name']),'summary'=>[
            'total'=>$rows->count(),'active'=>$rows->where('status','active')->count(),'scheduled'=>$rows->where('status','scheduled')->count(),'ended'=>$rows->where('status','ended')->count(),
        ]]);
    }

    public function flashSales()
    {
        return response(['data'=>FlashSaleCampaign::orderBy('id','DESC')->get(),'plans'=>Plan::orderBy('id')->get(['id','name'])]);
    }

    public function saveFlashSale(Request $request)
    {
        $data = $request->validate([
            'name'=>'required|string|max:100','name_en'=>'nullable|string|max:100','description'=>'nullable|string|max:1000','description_en'=>'nullable|string|max:1000',
            'starts_at'=>'required|integer','ends_at'=>'required|integer','audience'=>'required|in:all,new,existing','plan_ids'=>'nullable|array','plan_ids.*'=>'integer|exists:v2_plan,id',
            'periods'=>'nullable|array','periods.*'=>'in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price',
            'discount_type'=>'required|in:fixed_price,percent_off,amount_off','discount_value'=>'required|integer|min:1','minimum_amount'=>'required|integer|min:0',
            'allow_coupon'=>'required|boolean','allow_member_discount'=>'sometimes|boolean','priority'=>'required|integer|min:0|max:9999','per_user_limit'=>'nullable|integer|min:1','total_limit'=>'nullable|integer|min:1','enabled'=>'required|boolean',
        ]);
        if ($data['ends_at'] <= $data['starts_at']) abort(422, '结束时间必须晚于开始时间');
        if ($data['discount_type'] === 'percent_off' && $data['discount_value'] > 100) abort(422, '优惠比例不能超过 100%');
        $campaign = $request->input('id') ? FlashSaleCampaign::findOrFail($request->input('id')) : new FlashSaleCampaign();
        if (!array_key_exists('allow_member_discount', $data)) $data['allow_member_discount'] = $campaign->exists ? (bool)$campaign->allow_member_discount : true;
        if ($campaign->exists && $campaign->order_count > 0) {
            foreach (['discount_type','discount_value','plan_ids','periods','audience'] as $field) if (($campaign->{$field} ?? null) != ($data[$field] ?? null)) abort(422, '活动已有成交，优惠核心规则不可修改；请复制创建新活动');
        }
        $campaign->fill($data)->save();
        return response(['data'=>$campaign]);
    }

    public function dropFlashSale(Request $request)
    {
        $campaign = FlashSaleCampaign::findOrFail($request->input('id'));
        if ($campaign->order_count > 0) abort(422, '活动已有成交，只能停用');
        return response(['data'=>(bool)$campaign->delete()]);
    }

    private function status($x): string
    {
        if (!$x->enabled) return 'disabled';
        if ($x->starts_at && time() < $x->starts_at) return 'scheduled';
        if ($x->ends_at && time() > $x->ends_at) return 'ended';
        return 'active';
    }

    private function row($x, string $type): array
    {
        return ['key'=>$type.':'.$x->id,'source_id'=>$x->id,'type'=>$type,'name'=>$x->name,'name_en'=>$x->name_en,'starts_at'=>$x->starts_at,'ends_at'=>$x->ends_at,'status'=>$this->status($x),'enabled'=>(bool)$x->enabled,'scope'=>$x->audience ?? 'all','orders'=>(int)($x->order_count ?? $x->granted_count ?? 0),'revenue'=>(int)($x->revenue ?? 0),'discount_total'=>(int)($x->discount_total ?? $x->spent_amount ?? 0)];
    }

    private function couponRow(CouponTemplate $x): array
    {
        return ['key'=>'coupon:'.$x->id,'source_id'=>$x->id,'type'=>'coupon','name'=>$x->name,'name_en'=>$x->name_en,'starts_at'=>$x->starts_at,'ends_at'=>$x->ends_at,'status'=>$this->status($x),'enabled'=>(bool)$x->enabled,'scope'=>'优惠券适用用户','orders'=>(int)$x->used_count,'revenue'=>0,'discount_total'=>0];
    }
}

<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GiftcardGenerate;
use App\Models\Giftcard;
use App\Models\GiftcardRedemption;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GiftcardController extends Controller
{
    public function redemptions(Request $request)
    {
        $current = max((int)$request->input('current', 1), 1);
        $pageSize = min(max((int)$request->input('pageSize', 10), 1), 100);
        $builder = GiftcardRedemption::query()
            ->leftJoin('v2_user as user', 'user.id', '=', 'v2_giftcard_redemption.user_id')
            ->leftJoin('v2_plan as plan', 'plan.id', '=', 'v2_giftcard_redemption.plan_id')
            ->leftJoin('v2_giftcard as giftcard', 'giftcard.id', '=', 'v2_giftcard_redemption.giftcard_id')
            ->select([
                'v2_giftcard_redemption.id',
                'v2_giftcard_redemption.giftcard_id',
                'v2_giftcard_redemption.user_id',
                'v2_giftcard_redemption.name_snapshot',
                'v2_giftcard_redemption.type',
                'v2_giftcard_redemption.value',
                'v2_giftcard_redemption.plan_id',
                'v2_giftcard_redemption.redeemed_at',
                'user.email as user_email',
                'plan.name as plan_name',
                DB::raw('COALESCE(giftcard.code, v2_giftcard_redemption.code_snapshot) as code_snapshot'),
            ]);

        if ($request->filled('giftcard_id')) {
            $builder->where('v2_giftcard_redemption.giftcard_id', (int)$request->input('giftcard_id'));
        }
        if ($request->filled('type')) {
            $builder->where('v2_giftcard_redemption.type', (int)$request->input('type'));
        }
        if ($request->filled('search')) {
            $search = trim((string)$request->input('search'));
            $builder->where(function ($query) use ($search) {
                $query->where('user.email', 'like', "%{$search}%")
                    ->orWhere('v2_giftcard_redemption.name_snapshot', 'like', "%{$search}%")
                    ->orWhere('v2_giftcard_redemption.code_snapshot', 'like', "%{$search}%")
                    ->orWhere('giftcard.code', 'like', "%{$search}%");
                if (ctype_digit($search)) {
                    $query->orWhere('v2_giftcard_redemption.user_id', (int)$search);
                }
            });
        }

        $total = $builder->count();
        $records = $builder->orderBy('v2_giftcard_redemption.redeemed_at', 'DESC')
            ->orderBy('v2_giftcard_redemption.id', 'DESC')
            ->forPage($current, $pageSize)
            ->get();

        return response([
            'data' => $records,
            'total' => $total,
            'current' => $current,
            'pageSize' => $pageSize,
        ]);
    }

    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = max($request->input('pageSize', 10), 10);
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort', 'id');
        
        $builder = Giftcard::orderBy($sort, $sortType);
        $total = $builder->count();
        $giftcards = $builder->forPage($current, $pageSize)->get();

        return response([
            'data' => $giftcards,
            'total' => $total
        ]);
    }

    public function generate(GiftcardGenerate $request)
    {
        if ($request->input('generate_count')) {
            $this->multiGenerate($request);
            return;
        }

        $params = $request->validated();
        if (!$request->input('id')) {
            if (!isset($params['code'])) {
                $params['code'] = Helper::randomChar(16);
            }
            if (!Giftcard::create($params)) {
                abort(500, '礼品卡创建失败');
            }
        } else {
            $giftcard = Giftcard::find($request->input('id'));
            if (!$giftcard) {
                abort(404, '礼品卡不存在');
            }
            try {
                $giftcard->update($params);
            } catch (\Exception $e) {
                abort(500, '礼品卡保存失败');
            }
        }

        return response([
            'data' => true
        ]);
    }

    private function multiGenerate(GiftcardGenerate $request)
    {
        $giftcards = [];
        $giftcard = $request->validated();
        $giftcard['created_at'] = $giftcard['updated_at'] = time();
        unset($giftcard['generate_count']);
        
        for ($i = 0; $i < $request->input('generate_count'); $i++) {
            do {
                $giftcard['code'] = Helper::randomChar(16);
            } while (Giftcard::where('code', $giftcard['code'])->exists());
            array_push($giftcards, $giftcard);
        }
        DB::beginTransaction();
        try {
            if (!Giftcard::insert($giftcards)) {
                throw new \Exception('礼品卡批量生成失败');
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, $e->getMessage());
        }
        $giftcardvalue = $giftcard['value'] ?? 0;
        $data = "名称,类型,数值,开始时间,结束时间,可用次数,礼品卡卡密,生成时间\r\n";
        foreach ($giftcards as $giftcard) {
            $type = ['', '金额', '时长', '流量', '重置', '套餐'][$giftcard['type']];
            $value = ['', round($giftcardvalue/100, 2), $giftcardvalue . '天', $giftcardvalue . 'GB', '-', $giftcardvalue . '天'][$giftcard['type']];
            $startTime = date('Y-m-d H:i:s', $giftcard['started_at']);
            $endTime = date('Y-m-d H:i:s', $giftcard['ended_at']);
            $limitUse = $giftcard['limit_use'] ?? '不限制';
            $createTime = date('Y-m-d H:i:s', $giftcard['created_at']);
            $data .= "{$giftcard['name']},{$type},{$value},{$startTime},{$endTime},{$limitUse},{$giftcard['code']},{$createTime}\r\n";
        }

        // Return the CSV data as a response
       echo($data);
    }

    public function drop(Request $request)
    {
        $giftcardId = $request->input('id');
        if (empty($giftcardId)) {
            abort(400, '未找到礼品卡');
        }

        $giftcard = Giftcard::find($giftcardId);
        if (!$giftcard) {
            abort(404, '礼品卡不存在');
        }

        if (!$giftcard->delete()) {
            abort(500, '删除失败');
        }

        return response([
            'data' => true
        ]);
    }
}

<?php

declare(strict_types=1);
namespace CloudDesk;

final class Checks
{
    public static function token(array $version): string
    {
        return hash('sha256', json_encode(array_intersect_key($version,array_flip(['content','schema','field_order','mode','summary','template_id','status'])), JSON_THROW_ON_ERROR));
    }
    public static function state(array $data): void
    {
        foreach (['accounts','targets','versions','publications','settings','events'] as $table) {
            if (!isset($data[$table]) || !is_array($data[$table])) throw new ApiError('备份缺少数据表：'.$table);
        }
        if (isset($data['gtm_changes']) && !is_array($data['gtm_changes'])) throw new ApiError('GTM 历史记录格式无效');
        foreach (['accounts','targets','versions','publications','plans','templates'] as $table) {
            $ids = [];
            foreach ($data[$table] ?? [] as $row) {
                if (!is_array($row) || !isset($row['id']) || !is_int($row['id']) || $row['id'] < 1 || isset($ids[$row['id']])) throw new ApiError('备份记录 ID 无效或重复：'.$table);
                $ids[$row['id']] = true;
            }
        }
        if (isset($data['plans']) !== isset($data['templates'])) throw new ApiError('备份方案和模板不完整');
        foreach ($data['accounts'] as $account) {
            if (!in_array($account['provider'] ?? '',['aliyun','tencent','ucloud','cloudflare'],true) || !is_array($account['credentials'] ?? null) || !is_bool($account['enabled'] ?? null)) throw new ApiError('备份账号格式无效');
        }
        foreach ($data['targets'] as $target) {
            $account = Store::find($data,'accounts',$target['account_id'] ?? 0);
            if ($account['provider'] === 'cloudflare' || !preg_match('/^[a-z0-9-]{2,50}$/',$target['region'] ?? '') || !preg_match('/^[a-z0-9][a-z0-9.-]{1,250}$/',$target['bucket'] ?? '') || !is_string($target['key'] ?? null) || $target['key'] === '') throw new ApiError('备份存储目标无效');
        }
        foreach ($data['templates'] ?? [] as $template) Plans::checkSchema($template['schema']);
        foreach ($data['plans'] ?? [] as $plan) {
            Store::find($data,'templates',$plan['template_id'] ?? 0);
            if (!is_array($plan['targets'] ?? null)) throw new ApiError('备份方案目标无效');
            foreach ($plan['targets'] as $id) Store::find($data,'targets',$id);
        }
        $destinations=[];
        foreach ($data['plans'] ?? [] as $plan) {
            foreach ($plan['targets'] as $id) {
                $target=Store::find($data,'targets',$id);
                $account=Store::find($data,'accounts',$target['account_id']);
                $destination=json_encode([$account['provider'],$target['region'],$target['bucket'],$target['key']]);
                if (isset($destinations[$destination]) && $destinations[$destination]!==$plan['id']) throw new ApiError('备份中的不同方案共用发布地址');
                $destinations[$destination]=$plan['id'];
            }
        }
        foreach ($data['versions'] as $version) {
            if (!is_array($version['content'] ?? null) || ($version['content'] !== [] && array_is_list($version['content'])) || !in_array($version['mode'] ?? '',['plain','xor_base64','signed_xor_v2'],true)) throw new ApiError('备份配置修订无效');
            if (isset($data['plans'])) {
                Store::find($data,'plans',$version['plan_id'] ?? 0);
                Store::find($data,'templates',$version['template_id'] ?? 0);
            }
            if (isset($version['schema'])) { Plans::checkSchema($version['schema']); Plans::validate($version['content'],$version['schema']); }
            self::order($version['field_order'] ?? []);
        }
        foreach ($data['publications'] as $job) {
            Store::find($data,'versions',$job['version_id'] ?? 0);
            // Deleted targets remain valid historical references, but cannot be retried.
            if (!in_array($job['status'] ?? '',['publishing','success','failed'],true)) throw new ApiError('备份发布状态无效');
        }
    }
    public static function order(array $order): void
    {
        if (count($order) > 2000) throw new ApiError('字段排序过大');
        foreach ($order as $path => $keys) {
            $parts = json_decode((string)$path,true);
            if (!is_array($parts) || !array_is_list($parts) || count($parts)>32 || array_filter($parts,fn ($v)=>!is_string($v)) || !is_array($keys) || !array_is_list($keys) || count($keys)>2000 || array_filter($keys,fn ($v)=>!is_string($v)) || count(array_unique($keys))!==count($keys)) throw new ApiError('字段排序格式无效');
        }
    }
}

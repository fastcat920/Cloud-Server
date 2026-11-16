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
        foreach (['notification_channels','notification_sources','notification_jobs'] as $table) if (isset($data[$table]) && !is_array($data[$table])) throw new ApiError('通知备份格式无效');
        foreach (['accounts','targets','versions','publications','plans','templates','notification_channels','notification_sources','notification_jobs'] as $table) {
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
        foreach ($data['notification_channels'] ?? [] as $row) {
            if (($row['type'] ?? '')!=='telegram' || !is_string($row['token'] ?? null) || !is_bool($row['enabled'] ?? null) || !is_string($row['name'] ?? null) || !preg_match('/^(?:-?[0-9]+|@[a-zA-Z0-9_]{5,})$/',$row['chat_id'] ?? '') || (isset($row['thread_id']) && (!is_int($row['thread_id']) || $row['thread_id']<1))) throw new ApiError('通知渠道备份无效');
        }
        foreach ($data['notification_sources'] ?? [] as $row) {
            if (($row['kind'] ?? '')!=='aliyun' || !is_string($row['secret'] ?? null) || !is_bool($row['enabled'] ?? null) || !is_string($row['name'] ?? null)) throw new ApiError('告警来源备份无效');
            if (isset($row['format']) && !in_array($row['format'],['original','readable'],true)) throw new ApiError('通知排版备份无效');
            Store::find($data,'notification_channels',(int)($row['channel_id'] ?? 0));
        }
        foreach ($data['notification_jobs'] ?? [] as $row) if (!in_array($row['status'] ?? '',['pending','sending','retry','sent','failed','cancelled'],true) || !is_string($row['message'] ?? null) || !is_string($row['dedupe'] ?? null) || !preg_match('/^[a-f0-9]{64}$/',$row['dedupe']) || !is_int($row['channel_id'] ?? null) || $row['channel_id']<1 || !is_int($row['source_id'] ?? null) || $row['source_id']<0 || !is_string($row['source_name'] ?? null) || !is_string($row['channel_name'] ?? null) || !is_int($row['created_at'] ?? null) || !is_int($row['attempts'] ?? null) || !is_int($row['next_at'] ?? null)) throw new ApiError('消息投递记录备份无效');
        foreach ($data['notification_jobs'] ?? [] as $row) foreach (['part_index','part_attempts','part_count'] as $field) if (isset($row[$field]) && (!is_int($row[$field]) || $row[$field]<0)) throw new ApiError('消息分段记录备份无效');
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

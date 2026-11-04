<?php

declare(strict_types=1);

namespace CloudDesk;

final class Importer
{
    public function __construct(private Security $security)
    {
    }
    public function run(Store $store, array $legacy, string $key): array
    {
        if (!isset($legacy['v2_cloud_account']) && !isset($legacy['v2_client_config'])) {
            throw new \RuntimeException('请选择旧版 storage/app/private/state.json 文件');
        }
        $rows = fn ($table) => array_values($legacy[$table]['rows'] ?? []);
        $decrypt = fn ($value) => Security::legacyDecrypt($value, $key);
        return $store->transaction(function (array &$state) use ($rows, $decrypt) {
            if ($state['accounts'] || $state['targets']) {
                throw new \RuntimeException('请在尚未添加云账号的全新安装中导入，避免覆盖当前配置');
            }
            $mapping = [];
            $fingerprints = [];
            $add = function (array $r, string $provider, string $secretField) use (&$state, &$fingerprints, $decrypt) {
                $credentials = $provider === 'cloudflare' ? ['token' => $decrypt($r['api_token_encrypted'] ?? null)] : ['key' => $decrypt($r['access_key_id_encrypted'] ?? null),'secret' => $decrypt($r[$secretField] ?? null),'token' => $decrypt($r['security_token_encrypted'] ?? null)];
                $fingerprint = hash('sha256', $provider.json_encode($credentials));
                if (isset($fingerprints[$fingerprint])) {
                    return $fingerprints[$fingerprint];
                }
                $id = Store::id($state, 'accounts');
                $state['accounts'][] = ['id' => $id,'name' => $r['name'] ?? '旧版账号','provider' => $provider,'site' => $r['site'] ?? 'china','enabled' => (bool)($r['enabled'] ?? true),'credentials' => array_map(fn ($v) => $this->security->encrypt($v), $credentials)];
                return $fingerprints[$fingerprint] = $id;
            };
            foreach ($rows('v2_cloud_account') as $r) {
                $mapping[$r['id']] = $add($r, $r['provider'], 'access_key_secret_encrypted');
            }
            foreach ($rows('v2_aliyun_dns_account') as $r) {
                if (empty($r['cloud_account_id'])) {
                    $add($r, 'aliyun', 'access_key_secret_encrypted');
                }
            }
            foreach ($rows('v2_cloudflare_dns_account') as $r) {
                $add($r, 'cloudflare', 'api_token_encrypted');
            }
            $targetMap = [];
            foreach ($rows('v2_client_storage_target') as $r) {
                $provider = ['aliyun_oss' => 'aliyun','tencent_cos' => 'tencent','ucloud_us3' => 'ucloud'][$r['provider']] ?? throw new \RuntimeException('旧存储厂商不支持');
                $accountId = $mapping[$r['cloud_account_id'] ?? 0] ?? $add($r, $provider, 'secret_key_encrypted');
                $host = preg_replace('#^https?://#', '', $r['endpoint']);
                $region = match($provider) {
                    'aliyun' => preg_match('/oss-([a-z0-9-]+)/', $host, $m) ? $m[1] : 'cn-hangzhou','tencent' => preg_match('/cos\.([a-z0-9-]+)/', $host, $m) ? $m[1] : 'ap-guangzhou',default => preg_match('/(?:^|\.)(?:s3-)?([a-z0-9-]+)\.ufileos\.com/', $host, $m) ? $m[1] : 'cn-bj'
                };
                $id = Store::id($state, 'targets');
                $targetMap[$r['id']] = $id;
                $state['targets'][] = ['id' => $id,'name' => $r['name'],'account_id' => $accountId,'region' => $region,'bucket' => $r['bucket'],'key' => $r['object_key'],'url' => $r['public_url'] ?? Objects::publicUrl($provider, $region, $r['bucket'], $r['object_key']),'enabled' => (bool)($r['enabled'] ?? true),'public_read' => false];
            }
            $state['versions'] = [];
            $state['sequences']['versions'] = 0;
            foreach ($rows('v2_client_config') as $r) {
                $content = json_decode($r['content_json'], true, 512, JSON_THROW_ON_ERROR);
                $state['versions'][] = ['id' => (int)$r['id'],'version' => (int)$r['config_version'],'content' => $content,'mode' => $r['encryption_mode'],'status' => $r['status'] === 'publishing' ? 'partial' : $r['status'],'summary' => $r['change_summary'] ?? '旧版导入','created_at' => $r['created_at'] ?? time()];
                $state['sequences']['versions'] = max($state['sequences']['versions'], (int)$r['id'], (int)$r['config_version']);
            }
            if (!$state['versions']) {
                $state['versions'][] = ['id' => Store::id($state, 'versions'),'version' => 1,'content' => Configs::defaults(),'mode' => 'plain','status' => 'draft','summary' => '初始配置','created_at' => time()];
            }
            $settings = $rows('v2_client_config_setting')[0] ?? [];
            if (!empty($settings['legacy_xor_key_encrypted'])) {
                $state['settings']['xor_key'] = $this->security->encrypt($decrypt($settings['legacy_xor_key_encrypted']));
            }
            if (!empty($settings['signing_private_key_encrypted'])) {
                $state['settings']['signing_secret'] = $this->security->encrypt($decrypt($settings['signing_private_key_encrypted']));
            }
            $state['settings']['signing_public'] = $settings['signing_public_key'] ?? null;
            foreach ($rows('v2_client_config_publication') as $r) {
                if (!isset($targetMap[$r['target_id']])) {
                    continue;
                }
                $state['publications'][] = ['id' => Store::id($state, 'publications'),'version_id' => (int)$r['config_id'],'target_id' => $targetMap[$r['target_id']],'target_name' => '旧版发布记录','status' => $r['status'] === 'success' ? 'success' : 'failed','checksum' => $r['checksum'] ?? '','error' => $r['error'] ?? '','started_at' => $r['started_at'] ?? 0,'finished_at' => $r['finished_at'] ?? 0];
            }
            Store::event($state, 'import.v1', '旧版数据');
            return ['accounts' => count($state['accounts']),'targets' => count($state['targets']),'versions' => count($state['versions'])];
        });
    }
}

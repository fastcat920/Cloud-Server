<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\PublishClientRemoteConfig;
use App\Models\ClientConfigPublication;
use App\Models\ClientConfigSetting;
use App\Models\ClientRemoteConfig;
use App\Models\ClientStorageTarget;
use App\Services\ClientConfigCryptoService;
use App\Services\ClientConfigStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientRemoteConfigController extends Controller
{
    public function overview(Request $request)
    {
        $setting = ClientConfigSetting::current();
        $draft = null;
        $editId = (int)$request->query('edit_id', 0);
        if ($editId > 0) {
            $draft = ClientRemoteConfig::where('id', $editId)->where('status', 'draft')->first();
        }
        if (!$draft) $draft = ClientRemoteConfig::where('status', 'draft')->orderByDesc('config_version')->first();
        $published = ClientRemoteConfig::where('status', 'published')->orderByDesc('config_version')->first();
        return response([
            'data' => [
                'draft' => $draft ? $this->configRow($draft, true) : null,
                'published' => $published ? $this->configRow($published, true) : null,
                'versions' => ClientRemoteConfig::orderByDesc('config_version')->limit(50)->get()->map(function ($row) { return $this->configRow($row); }),
                'targets' => ClientStorageTarget::orderByDesc('is_primary')->orderBy('id')->get()->map(function ($row) { return $this->targetRow($row); }),
                'publications' => ClientConfigPublication::with([
                    'target:id,name,provider',
                    'config:id,config_version,encryption_mode,change_summary',
                ])->orderByDesc('id')->limit(100)->get(),
                'settings' => [
                    'encryption_mode' => $setting->encryption_mode,
                    'has_xor_key' => (bool)$setting->legacy_xor_key_encrypted,
                    'signing_public_key' => $setting->signing_public_key,
                    'has_signing_key' => (bool)$setting->signing_private_key_encrypted,
                ],
            ],
        ]);
    }

    public function saveDraft(Request $request, ClientConfigCryptoService $crypto)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|exists:v2_client_config,id',
            'content' => 'required|array',
            'content.panel_type' => 'required|string|max:50',
            'content.domains' => 'required|array|min:1|max:20',
            'content.domains.*' => 'required|url|max:1000',
            'content.gateway_urls' => 'nullable|array|max:20',
            'content.gateway_urls.*' => 'required|url|max:1000',
            'content.update' => 'required|array',
            'content.update.schema_version' => 'required|integer|in:2',
            'content.update.platforms' => 'required|array|min:1',
            'content.contact' => 'required|array',
            'content.features' => 'required|array',
            'content.latency.display_discount_percent' => 'nullable|integer|min:0|max:90',
            'encryption_mode' => 'required|in:xor_base64,plain,signed_xor_v2',
            'change_summary' => 'nullable|string|max:500',
        ]);
        $this->validateRemoteUrls($data['content']);

        $row = !empty($data['id']) ? ClientRemoteConfig::findOrFail($data['id']) : null;
        if (!$row || !in_array($row->status, ['draft', 'partial'], true)) {
            $row = new ClientRemoteConfig();
            $row->config_version = ((int)ClientRemoteConfig::max('config_version')) + 1;
            $row->status = 'draft';
        }
        $content = $this->normalizeContent($data['content']);
        $content['config_version'] = (string)$row->config_version;
        $row->content_json = $crypto->canonicalJson($content);
        $row->checksum = $crypto->checksum($content);
        $row->encryption_mode = $data['encryption_mode'];
        $row->change_summary = trim((string)($data['change_summary'] ?? '')) ?: null;
        if ($row->status === 'partial') $row->status = 'draft';
        $row->save();
        return response(['data' => $this->configRow($row, true)]);
    }

    public function cloneVersion(Request $request, ClientConfigCryptoService $crypto)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_client_config,id']);
        $source = ClientRemoteConfig::findOrFail($data['id']);
        $content = $source->content;
        $version = ((int)ClientRemoteConfig::max('config_version')) + 1;
        $content['config_version'] = (string)$version;
        $contentJson = $crypto->canonicalJson($content);
        $row = ClientRemoteConfig::create([
            'config_version' => $version,
            'content_json' => $contentJson,
            'status' => 'draft',
            'encryption_mode' => $source->encryption_mode,
            'checksum' => hash('sha256', $contentJson),
            'change_summary' => '基于 v' . $source->config_version . ' 创建',
        ]);
        return response(['data' => $this->configRow($row, true)]);
    }

    public function dropVersion(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_client_config,id']);
        $row = ClientRemoteConfig::findOrFail($data['id']);
        if ($row->status !== 'draft') abort(422, '只有未发布的草稿可以删除');
        if ($row->publications()->exists()) abort(422, '该草稿已有发布记录，不能删除');
        $row->delete();
        return response(['data' => true]);
    }

    public function saveSettings(Request $request, ClientConfigCryptoService $crypto)
    {
        $data = $request->validate([
            'encryption_mode' => 'required|in:xor_base64,plain,signed_xor_v2',
            'xor_key' => 'nullable|string|min:8|max:500',
            'regenerate_signing_key' => 'sometimes|boolean',
        ]);
        $setting = ClientConfigSetting::current();
        $setting->encryption_mode = $data['encryption_mode'];
        if (!empty($data['xor_key'])) $setting->legacy_xor_key_encrypted = Crypt::encryptString($data['xor_key']);
        if (!empty($data['regenerate_signing_key'])) {
            $setting->signing_public_key = null;
            $setting->signing_private_key_encrypted = null;
        }
        $setting->save();
        if ($setting->encryption_mode === 'signed_xor_v2') $crypto->ensureSigningKeyPair($setting);
        app(\App\Services\CloudCredentials::class)->migrate();
        return response(['data' => [
            'encryption_mode' => $setting->encryption_mode,
            'has_xor_key' => (bool)$setting->legacy_xor_key_encrypted,
            'signing_public_key' => $setting->signing_public_key,
            'has_signing_key' => (bool)$setting->signing_private_key_encrypted,
        ]]);
    }

    public function saveTarget(Request $request, ClientConfigStorageService $storage)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|exists:v2_client_storage_target,id',
            'name' => 'required|string|max:100',
            'provider' => 'required|in:aliyun_oss,tencent_cos,ucloud_us3',
            'enabled' => 'required|boolean',
            'is_primary' => 'required|boolean',
            'endpoint' => ['required', 'string', 'max:255', 'regex:/^(https?:\/\/)?[a-z0-9.-]+(?::[0-9]+)?$/i'],
            'bucket' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9.-]*$/i'],
            'object_key' => ['required', 'string', 'max:500', 'regex:/^[^?#]+$/'],
            'public_url' => 'nullable|url|max:1000',
            'cloud_account_id' => 'required|integer|exists:v2_cloud_account,id',
            'access_key_id' => 'nullable|string|max:500',
            'secret_key' => 'nullable|string|max:1000',
            'security_token' => 'nullable|string|max:2000',
        ]);
        $target = !empty($data['id']) ? ClientStorageTarget::findOrFail($data['id']) : new ClientStorageTarget();
        app(\App\Services\CloudCredentials::class)->account($data['cloud_account_id'], \App\Services\CloudCredentials::PROVIDERS[$data['provider']]);
        $target->cloud_account_id=$data['cloud_account_id'];
        foreach (['name','provider','enabled','is_primary','endpoint','bucket','object_key'] as $field) {
            $target->{$field} = $data[$field] ?? null;
        }
        $target->region = null;
        $target->public_url = trim((string)($data['public_url'] ?? ''));
        if ($target->public_url === '') {
            $target->public_url = $storage->defaultPublicUrlFor($target, $target->object_key);
        }
        $target->access_key_id_encrypted=null; $target->secret_key_encrypted=null; $target->security_token_encrypted=null;
        if ($target->is_primary) ClientStorageTarget::where('id', '<>', $target->id ?: 0)->update(['is_primary' => false, 'updated_at' => time()]);
        $target->save();
        return response(['data' => $this->targetRow($target)]);
    }

    public function dropTarget(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_client_storage_target,id']);
        $target = ClientStorageTarget::findOrFail($data['id']);
        if ($target->publications()->whereIn('status', ['queued', 'publishing'])->exists()) abort(422, '该目标有正在执行的发布任务，暂时无法删除');
        return response(['data' => (bool)$target->delete()]);
    }

    public function testTarget(Request $request, ClientConfigStorageService $storage)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_client_storage_target,id']);
        $target = ClientStorageTarget::findOrFail($data['id']);
        $key = preg_replace('#[^/]+$#', 'connection-test.txt', ltrim($target->object_key, '/'));
        $body = 'FastCat storage connection test ' . gmdate('c');
        try {
            $storage->upload($target, $key, $body);
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['target' => '上传测试失败：' . $error->getMessage()]);
        }
        $url = $storage->publicUrlFor($target, $key);
        $verificationError = null;
        try {
            $storage->verifyPublicObject($url, $body);
        } catch (\Throwable $error) {
            $verificationError = $error;
        }
        $cleanupError = null;
        try {
            $storage->deleteObject($target, $key);
        } catch (\Throwable $error) {
            $cleanupError = $error;
        }
        if ($verificationError) {
            $cleanupMessage = $cleanupError ? '；此外，测试文件未能自动删除：' . $cleanupError->getMessage() : '';
            $permissionHint = $target->provider === 'ucloud_us3'
                ? '请确认当前地域支持单文件权限控制，且公钥/私钥属于 Bucket 创建账户并具有 PutObjectAcl 权限'
                : '请确认 Bucket 未开启“阻止公共访问”，且访问密钥具有设置对象 ACL 的权限';
            throw ValidationException::withMessages(['target' => '文件已上传，但公开读取失败：' . $verificationError->getMessage() . '。' . $permissionHint . $cleanupMessage . '。']);
        }
        return response(['data' => [
            'success' => true,
            'url' => $url,
            'cleanup_warning' => $cleanupError ? $cleanupError->getMessage() : null,
        ]]);
    }

    public function preview(Request $request, ClientConfigCryptoService $crypto)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:v2_client_config,id',
            'encryption_mode' => 'required|in:xor_base64,plain,signed_xor_v2',
        ]);
        $row = ClientRemoteConfig::findOrFail($data['id']);
        $row->encryption_mode = $data['encryption_mode'];
        if ($data['encryption_mode'] === 'signed_xor_v2') {
            $crypto->ensureSigningKeyPair(ClientConfigSetting::current());
        }
        $payload = $crypto->encode($row);
        return response(['data' => [
            'payload' => $payload,
            'bytes' => strlen($payload),
            'checksum' => hash('sha256', $payload),
            'encryption_mode' => $row->encryption_mode,
        ]]);
    }

    public function publish(Request $request, ClientConfigCryptoService $crypto)
    {
        $data = $request->validate([
            'config_id' => 'required|integer|exists:v2_client_config,id',
            'target_ids' => 'required|array|min:1',
            'target_ids.*' => 'integer|exists:v2_client_storage_target,id',
        ]);
        $config = ClientRemoteConfig::findOrFail($data['config_id']);
        if ($config->status === 'publishing') abort(422, '该版本正在发布，请勿重复提交');
        $targets = ClientStorageTarget::whereIn('id', array_unique($data['target_ids']))->where('enabled', true)->get();
        if ($targets->isEmpty()) abort(422, '没有可用的云存储目标');
        $payload = $crypto->encode($config);
        $checksum = hash('sha256', $payload);

        $publications = \App\Support\FileStore\Store::transaction(function () use ($config, $targets, $checksum) {
            $config->status = 'publishing';
            $config->save();
            $rows = collect();
            foreach ($targets as $target) {
                $row = ClientConfigPublication::updateOrCreate(
                    ['config_id' => $config->id, 'target_id' => $target->id],
                    ['status' => 'queued', 'object_key' => $target->object_key, 'public_url' => null, 'etag' => null, 'checksum' => $checksum, 'error' => null, 'attempts' => 0, 'started_at' => null, 'finished_at' => null]
                );
                $rows->push($row);
            }
            return $rows;
        });
        foreach ($publications as $publication) PublishClientRemoteConfig::dispatch($publication->id)->onQueue('default');
        return response(['data' => $publications]);
    }

    public function retryPublication(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_client_config_publication,id']);
        $row = ClientConfigPublication::findOrFail($data['id']);
        if (in_array($row->status, ['queued', 'publishing'], true)) abort(422, '该任务正在执行');
        $row->status = 'queued';
        $row->error = null;
        $row->finished_at = null;
        $row->save();
        $row->config()->update(['status' => 'publishing', 'updated_at' => time()]);
        PublishClientRemoteConfig::dispatch($row->id)->onQueue('default');
        return response(['data' => $row]);
    }

    private function configRow(ClientRemoteConfig $row, bool $withContent = false): array
    {
        $data = $row->toArray();
        unset($data['content_json']);
        if ($withContent) $data['content'] = $row->content;
        return $data;
    }

    private function targetRow(ClientStorageTarget $row): array
    {
        $data = $row->toArray();
        $data['has_credentials'] = (bool)($row->access_key_id_encrypted && $row->secret_key_encrypted);
        $data['access_key_hint'] = $row->access_key_id_encrypted ? '已安全保存' : '未设置';
        return $data;
    }

    private function validateRemoteUrls(array $content): void
    {
        $contact = (array)($content['contact'] ?? []);
        $urls = array_merge(
            (array)($content['domains'] ?? []),
            (array)($content['gateway_urls'] ?? []),
            (array)($content['gateway_url'] ?? []),
            (array)($contact['website'] ?? []),
            [
                $contact['crisp_proxy_url'] ?? '',
                $contact['invite_domain'] ?? '',
                $contact['telegram_group'] ?? ($contact['telegram'] ?? ''),
            ]
        );
        foreach ((array)($content['update']['platforms'] ?? []) as $platform) {
            $urls[] = is_array($platform) ? ($platform['url'] ?? '') : '';
        }
        foreach ($urls as $url) {
            $url = trim((string)$url);
            if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url))) {
                throw ValidationException::withMessages(['content' => '远程配置中的网络地址必须是有效的 HTTP 或 HTTPS 地址：' . $url]);
            }
        }
    }

    private function normalizeContent(array $content): array
    {
        $content['api_prefix'] = trim((string)($content['api_prefix'] ?? '/api/v1')) ?: '/api/v1';
        $content['gateway_urls'] = array_values(array_filter(array_map('trim', (array)($content['gateway_urls'] ?? ($content['gateway_url'] ?? [])))));
        unset($content['gateway_url']);

        $contact = (array)($content['contact'] ?? []);
        $contact['website'] = array_values(array_filter(array_map('trim', (array)($contact['website'] ?? []))));
        unset($contact['salesmartly_token']);
        $content['contact'] = $contact;
        if (isset($content['ticket']) && is_array($content['ticket'])) {
            unset($content['ticket']['imgbb_api_key']);
            if ($content['ticket'] === []) unset($content['ticket']);
        }

        $featureKeys = [
            'balance_enabled',
            'devices_enabled',
            'gift_card_enabled',
            'join_group_enabled',
            'knowledge_base_enabled',
            'orders_enabled',
            'tickets_enabled',
            'traffic_details_enabled',
        ];
        $incomingFeatures = (array)($content['features'] ?? []);
        $features = [];
        foreach ($featureKeys as $key) {
            $legacyKey = preg_replace('/_enabled$/', '', $key);
            $features[$key] = array_key_exists($key, $incomingFeatures)
                ? (bool)$incomingFeatures[$key]
                : (array_key_exists($legacyKey, $incomingFeatures) ? (bool)$incomingFeatures[$legacyKey] : true);
        }
        $content['features'] = $features;

        $content['latency'] = [
            'display_discount_percent' => max(0, min(90, (int)($content['latency']['display_discount_percent'] ?? 0))),
        ];

        $update = (array)($content['update'] ?? []);
        $legacyRows = (array)($update['latest'] ?? []);
        $platformRows = (array)($update['platforms'] ?? []);
        $supportedPlatforms = ['android', 'windows', 'macos', 'linux', 'ios', 'tvos'];
        $normalizedPlatforms = [];
        foreach ($supportedPlatforms as $platform) {
            $hasPlatformRow = array_key_exists($platform, $platformRows) && is_array($platformRows[$platform]);
            $row = $hasPlatformRow ? $platformRows[$platform] : [];
            $legacy = isset($legacyRows[$platform]) && is_array($legacyRows[$platform]) ? $legacyRows[$platform] : [];
            $version = trim((string)($row['latest_version'] ?? $row['version'] ?? $legacy['version'] ?? ''));
            $url = trim((string)($row['url'] ?? $legacy['url'] ?? ''));
            $appId = trim((string)($row['app_id'] ?? ''));
            $enabled = $hasPlatformRow
                ? (($row['enabled'] ?? true) === true)
                : ($version !== '' || $url !== '');
            $force = ($row['force'] ?? $legacy['force'] ?? false) === true;
            $changelog = $row['changelog'] ?? [];
            if (is_string($changelog)) $changelog = ['zh_CN' => $changelog];
            if (!is_array($changelog)) $changelog = [];

            $isAppStore = in_array($platform, ['ios', 'tvos'], true);
            if (!$isAppStore) $appId = '';
            if ($isAppStore) $url = '';
            if ($enabled && $version === '') {
                throw ValidationException::withMessages(['content.update.platforms.' . $platform => strtoupper($platform) . ' 启用后必须填写最新版本']);
            }
            if ($enabled && $isAppStore && $appId === '') {
                throw ValidationException::withMessages(['content.update.platforms.' . $platform => strtoupper($platform) . ' 启用后必须填写商店 ID']);
            }
            if ($enabled && !$isAppStore && $url === '') {
                throw ValidationException::withMessages(['content.update.platforms.' . $platform => strtoupper($platform) . ' 启用后必须填写下载地址']);
            }
            $normalizedRow = [
                'enabled' => $enabled,
                'source' => $isAppStore ? 'app_store' : 'direct',
                'latest_version' => $version,
                'min_supported_version' => trim((string)($row['min_supported_version'] ?? '')),
                'url' => $url,
                'force' => $force,
                'changelog' => [
                    'zh_CN' => trim((string)($changelog['zh_CN'] ?? $changelog['zh-CN'] ?? '')),
                    'en_US' => trim((string)($changelog['en_US'] ?? $changelog['en-US'] ?? '')),
                ],
            ];
            if ($appId !== '') $normalizedRow['app_id'] = $appId;
            $normalizedPlatforms[$platform] = $normalizedRow;
        }
        $update['schema_version'] = 2;
        $update['platforms'] = $normalizedPlatforms;
        unset($update['latest'], $update['min_version'], $update['changelog'], $update['_comment_legacy']);
        $content['update'] = $update;

        return $content;
    }
}

<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudflareDnsAccount;
use App\Services\CloudflareDnsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CloudflareDnsController extends Controller
{
    public function overview()
    {
        return response(['data' => [
            'accounts' => CloudflareDnsAccount::orderByDesc('is_default')->orderBy('id')->get()->map(function ($account) {
                return $this->accountRow($account);
            }),
            'endpoint' => CloudflareDnsService::ENDPOINT,
        ]]);
    }

    public function saveAccount(Request $request)
    {
        $data = $request->validate([
            'id' => 'nullable|integer|exists:v2_cloudflare_dns_account,id',
            'name' => 'required|string|max:100',
            'api_token' => 'nullable|string|max:3000',
            'enabled' => 'required|boolean',
            'is_default' => 'required|boolean',
        ]);
        $account = !empty($data['id']) ? CloudflareDnsAccount::findOrFail($data['id']) : new CloudflareDnsAccount();
        if (!$account->exists && empty($data['api_token'])) {
            throw ValidationException::withMessages(['api_token' => '新建账号时必须填写 API Token']);
        }
        $account->name = trim($data['name']);
        $account->enabled = (bool)$data['enabled'];
        $account->is_default = (bool)$data['is_default'];
        if (!empty($data['api_token'])) {
            $token = CloudflareDnsService::normalizeToken($data['api_token']);
            if ($token === '') {
                throw ValidationException::withMessages(['api_token' => '请输入有效的 API Token']);
            }
            $account->api_token_encrypted = Crypt::encryptString($token);
        }
        if ($account->is_default) {
            CloudflareDnsAccount::where('id', '<>', $account->id ?: 0)->update(['is_default' => false, 'updated_at' => time()]);
        }
        $account->save();
        if (!CloudflareDnsAccount::where('is_default', true)->exists()) {
            $account->is_default = true;
            $account->save();
        }
        return response(['data' => $this->accountRow($account)]);
    }

    public function testAccount(Request $request, CloudflareDnsService $service)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_cloudflare_dns_account,id']);
        $account = CloudflareDnsAccount::findOrFail($data['id']);
        try {
            $service->verifyToken($account);
            $zones = $service->listZones($account);
            $account->last_connected_at = time();
            $account->last_error = null;
            $account->save();
            return response(['data' => ['success' => true, 'zone_count' => count($zones)]]);
        } catch (\Throwable $error) {
            $this->rememberError($account, $error);
            throw ValidationException::withMessages(['account' => $error->getMessage()]);
        }
    }

    public function dropAccount(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_cloudflare_dns_account,id']);
        $account = CloudflareDnsAccount::findOrFail($data['id']);
        $wasDefault = $account->is_default;
        $account->delete();
        if ($wasDefault) {
            $next = CloudflareDnsAccount::orderBy('id')->first();
            if ($next) {
                $next->is_default = true;
                $next->save();
            }
        }
        return response(['data' => true]);
    }

    public function zones(Request $request, CloudflareDnsService $service)
    {
        $account = $this->account($request);
        try {
            $zones = $service->listZones($account);
            $account->last_connected_at = time();
            $account->last_error = null;
            $account->save();
        } catch (\Throwable $error) {
            $this->rememberError($account, $error);
            throw ValidationException::withMessages(['zones' => $error->getMessage()]);
        }
        return response(['data' => ['items' => array_map(function ($zone) {
            return [
                'id' => $zone['id'] ?? '',
                'name' => $zone['name'] ?? '',
                'status' => $zone['status'] ?? '',
                'paused' => (bool)($zone['paused'] ?? false),
                'type' => $zone['type'] ?? '',
                'plan_name' => $zone['plan']['name'] ?? '',
                'name_servers' => $zone['name_servers'] ?? [],
                'modified_on' => $zone['modified_on'] ?? null,
            ];
        }, $zones)]]);
    }

    public function records(Request $request, CloudflareDnsService $service)
    {
        $data = $request->validate([
            'account_id' => 'required|integer|exists:v2_cloudflare_dns_account,id',
            'zone_id' => 'required|string|max:64',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:10|max:100',
            'type' => 'nullable|string|max:10',
            'name' => 'nullable|string|max:253',
        ]);
        $account = CloudflareDnsAccount::where('enabled', true)->findOrFail($data['account_id']);
        $query = [
            'page' => (int)($data['page'] ?? 1),
            'per_page' => (int)($data['per_page'] ?? 20),
            'order' => 'type',
            'direction' => 'asc',
        ];
        if (!empty($data['type'])) $query['type'] = strtoupper($data['type']);
        if (!empty($data['name'])) $query['name'] = trim($data['name']);
        try {
            $result = $service->listRecords($account, $data['zone_id'], $query);
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['records' => $error->getMessage()]);
        }
        $info = $result['result_info'] ?? [];
        return response(['data' => [
            'items' => $result['result'] ?? [],
            'page' => (int)($info['page'] ?? $query['page']),
            'per_page' => (int)($info['per_page'] ?? $query['per_page']),
            'total' => (int)($info['total_count'] ?? count($result['result'] ?? [])),
        ]]);
    }

    public function saveRecord(Request $request, CloudflareDnsService $service)
    {
        $data = $request->validate([
            'account_id' => 'required|integer|exists:v2_cloudflare_dns_account,id',
            'zone_id' => 'required|string|max:64',
            'record_id' => 'nullable|string|max:64',
            'type' => ['required', Rule::in(['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'CAA', 'NS', 'SRV'])],
            'name' => 'required|string|max:253',
            'content' => 'nullable|string|max:8192',
            'ttl' => 'required|integer|min:1|max:86400',
            'proxied' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:0|max:65535',
            'comment' => 'nullable|string|max:500',
            'data' => 'nullable|array',
            'data.flags' => 'nullable|integer|min:0|max:255',
            'data.tag' => 'nullable|string|max:32',
            'data.value' => 'nullable|string|max:4096',
            'data.priority' => 'nullable|integer|min:0|max:65535',
            'data.weight' => 'nullable|integer|min:0|max:65535',
            'data.port' => 'nullable|integer|min:0|max:65535',
            'data.target' => 'nullable|string|max:253',
        ]);
        $account = CloudflareDnsAccount::where('enabled', true)->findOrFail($data['account_id']);
        $payload = $this->recordPayload($data);
        try {
            $result = !empty($data['record_id'])
                ? $service->updateRecord($account, $data['zone_id'], $data['record_id'], $payload)
                : $service->createRecord($account, $data['zone_id'], $payload);
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['record' => $error->getMessage()]);
        }
        return response(['data' => $result['result'] ?? true]);
    }

    public function dropRecord(Request $request, CloudflareDnsService $service)
    {
        $data = $request->validate([
            'account_id' => 'required|integer|exists:v2_cloudflare_dns_account,id',
            'zone_id' => 'required|string|max:64',
            'record_id' => 'required|string|max:64',
        ]);
        $account = CloudflareDnsAccount::where('enabled', true)->findOrFail($data['account_id']);
        try {
            $service->deleteRecord($account, $data['zone_id'], $data['record_id']);
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['record' => $error->getMessage()]);
        }
        return response(['data' => true]);
    }

    private function recordPayload(array $data)
    {
        $type = strtoupper($data['type']);
        $payload = [
            'type' => $type,
            'name' => trim($data['name']),
            'ttl' => (int)$data['ttl'],
            'comment' => trim((string)($data['comment'] ?? '')),
        ];
        if (in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            $payload['content'] = trim((string)($data['content'] ?? ''));
            $payload['proxied'] = (bool)($data['proxied'] ?? false);
        } elseif ($type === 'MX') {
            $payload['content'] = trim((string)($data['content'] ?? ''));
            $payload['priority'] = (int)($data['priority'] ?? 0);
        } elseif ($type === 'CAA') {
            $payload['data'] = [
                'flags' => (int)($data['data']['flags'] ?? 0),
                'tag' => trim((string)($data['data']['tag'] ?? 'issue')),
                'value' => trim((string)($data['data']['value'] ?? '')),
            ];
        } elseif ($type === 'SRV') {
            $payload['data'] = [
                'priority' => (int)($data['data']['priority'] ?? 0),
                'weight' => (int)($data['data']['weight'] ?? 0),
                'port' => (int)($data['data']['port'] ?? 0),
                'target' => trim((string)($data['data']['target'] ?? '')),
            ];
        } else {
            $payload['content'] = trim((string)($data['content'] ?? ''));
        }
        return $payload;
    }

    private function account(Request $request)
    {
        $data = $request->validate(['account_id' => 'required|integer|exists:v2_cloudflare_dns_account,id']);
        return CloudflareDnsAccount::where('enabled', true)->findOrFail($data['account_id']);
    }

    private function accountRow(CloudflareDnsAccount $account)
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'token_masked' => '••••••••',
            'has_api_token' => !empty($account->api_token_encrypted),
            'enabled' => (bool)$account->enabled,
            'is_default' => (bool)$account->is_default,
            'last_connected_at' => $account->last_connected_at,
            'last_error' => $account->last_error,
        ];
    }

    private function rememberError(CloudflareDnsAccount $account, \Throwable $error)
    {
        $account->last_error = mb_substr($error->getMessage(), 0, 1000);
        $account->save();
    }
}

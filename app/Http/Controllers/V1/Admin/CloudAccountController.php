<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CloudAccount;
use App\Services\CloudBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class CloudAccountController extends Controller
{
    public function overview()
    {
        app(\App\Services\CloudCredentials::class)->migrate();
        return response(['data' => [
            'accounts' => CloudAccount::orderBy('provider')->orderBy('id')->get()->map(function ($account) {
                return $this->accountRow($account);
            })->values(),
            'periods' => [
                'current' => now()->format('Y-m'),
                'previous' => now()->subMonthNoOverflow()->format('Y-m'),
            ],
        ]]);
    }

    public function save(Request $request, CloudBillingService $service)
    {
        app(\App\Services\CloudCredentials::class)->migrate();
        $data = $request->validate([
            'id' => 'nullable|integer|exists:v2_cloud_account,id',
            'name' => 'required|string|max:100',
            'account_id' => 'nullable|string|max:191',
            'provider' => 'required|in:aliyun,tencent,ucloud',
            'site' => 'nullable|in:china,international',
            'access_key_id' => 'nullable|string|max:500',
            'access_key_secret' => 'nullable|string|max:1000',
            'security_token' => 'nullable|string|max:3000',
            'clear_security_token' => 'sometimes|boolean',
            'enabled' => 'required|boolean',
            'sync_balance' => 'sometimes|boolean',
        ]);
        $account = !empty($data['id']) ? CloudAccount::findOrFail($data['id']) : new CloudAccount();
        if ($account->exists && $account->provider !== $data['provider'] && app(\App\Services\CloudCredentials::class)->references($account->id)) abort(422,'账号已被引用，不能更换厂商');
        $site = $data['site'] ?? ($account->site ?: 'china');
        $providerChanged = $account->exists && $account->provider !== $data['provider'];
        $siteChanged = $account->exists && ($account->site ?: 'china') !== $site;
        if ((!$account->exists || $providerChanged) && (empty($data['access_key_id']) || empty($data['access_key_secret']))) {
            throw ValidationException::withMessages(['access_key_id' => '新建账号或更换云服务商时必须填写完整的访问密钥']);
        }
        $account->name = trim($data['name']);
        if ($data['provider'] === 'ucloud') {
            $account->account_id = trim((string)($data['account_id'] ?? '')) ?: null;
        } elseif (!$account->exists || $providerChanged) {
            $account->account_id = null;
        }
        $account->provider = $data['provider'];
        $account->site = $site;
        $account->enabled = (bool)$data['enabled'];
        if ($providerChanged) {
            $account->security_token_encrypted = null;
        }
        if ($providerChanged || $siteChanged || !empty($data['access_key_id']) || !empty($data['access_key_secret']) || !empty($data['security_token']) || !empty($data['clear_security_token'])) {
            if ($data['provider'] !== 'ucloud') {
                $account->account_id = null;
            }
            $account->currency = null;
            $account->balance = null;
            $account->current_month_amount = null;
            $account->previous_month_amount = null;
            $account->last_synced_at = null;
            $account->last_error = null;
        }
        if (!empty($data['access_key_id'])) {
            $account->access_key_id_encrypted = Crypt::encryptString(trim($data['access_key_id']));
        }
        if (!empty($data['access_key_secret'])) {
            $account->access_key_secret_encrypted = Crypt::encryptString(trim($data['access_key_secret']));
        }
        if (!empty($data['clear_security_token'])) {
            $account->security_token_encrypted = null;
        } elseif (array_key_exists('security_token', $data) && trim((string)$data['security_token']) !== '') {
            $account->security_token_encrypted = Crypt::encryptString(trim($data['security_token']));
        }
        $account->save();

        if ($account->enabled && !empty($data['sync_balance'])) {
            $this->syncAccount($account, $service);
        }
        return response(['data' => $this->accountRow($account->fresh())]);
    }

    public function refresh(Request $request, CloudBillingService $service)
    {
        $data = $request->validate(['id' => 'required|integer|exists:v2_cloud_account,id']);
        $account = CloudAccount::where('enabled', true)->findOrFail($data['id']);
        $this->syncAccount($account, $service);
        if ($account->last_error) {
            throw ValidationException::withMessages(['account' => $account->last_error]);
        }
        return response(['data' => $this->accountRow($account->fresh())]);
    }

    public function refreshAll(CloudBillingService $service)
    {
        $results = [];
        CloudAccount::where('enabled', true)->orderBy('id')->get()->each(function ($account) use ($service, &$results) {
            $this->syncAccount($account, $service);
            $results[] = $this->accountRow($account->fresh());
        });
        return response(['data' => ['accounts' => $results]]);
    }

    public function drop(Request $request)
    {
        app(\App\Services\CloudCredentials::class)->migrate();
        $data = $request->validate(['id' => 'required|integer|exists:v2_cloud_account,id']);
        $references=app(\App\Services\CloudCredentials::class)->references($data['id']);
        if ($references) abort(422,'账号正在使用：'.implode('、',$references));
        CloudAccount::findOrFail($data['id'])->delete();
        return response(['data' => true]);
    }

    private function syncAccount(CloudAccount $account, CloudBillingService $service)
    {
        try {
            $snapshot = $service->snapshot($account);
            if (!empty($snapshot['account_id'])) {
                $account->account_id = $snapshot['account_id'];
            }
            $account->balance = $snapshot['balance'];
            $account->current_month_amount = $snapshot['current_month_amount'];
            $account->previous_month_amount = $snapshot['previous_month_amount'];
            $account->currency = $snapshot['currency'];
            $account->last_synced_at = time();
            $account->last_error = null;
        } catch (\Throwable $error) {
            $account->last_error = mb_substr($error->getMessage(), 0, 1000);
        }
        $account->save();
    }

    private function accountRow(CloudAccount $account)
    {
        $reference = max((float)($account->current_month_amount ?? 0), (float)($account->previous_month_amount ?? 0));
        $coverage = $reference > 0 && $account->balance !== null ? (float)$account->balance / $reference : null;
        if (!$account->enabled) {
            $health = 'disabled';
        } elseif ($account->last_error) {
            $health = 'error';
        } elseif ($account->balance === null) {
            $health = 'unknown';
        } elseif ($reference <= 0) {
            $health = (float)$account->balance > 0 ? 'healthy' : 'recharge';
        } elseif ($coverage < 1) {
            $health = 'recharge';
        } elseif ($coverage < 1.5) {
            $health = 'low';
        } else {
            $health = 'healthy';
        }
        return [
            'id' => $account->id,
            'name' => $account->name,
            'account_id' => $account->account_id,
            'provider' => $account->provider,
            'site' => $account->site ?: 'china',
            'enabled' => (bool)$account->enabled,
            'currency' => $account->currency ?: 'CNY',
            'balance' => $account->balance === null ? null : (float)$account->balance,
            'current_month_amount' => $account->current_month_amount === null ? null : (float)$account->current_month_amount,
            'previous_month_amount' => $account->previous_month_amount === null ? null : (float)$account->previous_month_amount,
            'coverage_months' => $coverage === null ? null : round($coverage, 2),
            'health' => $health,
            'last_synced_at' => $account->last_synced_at,
            'last_error' => $account->last_error,
            'references' => app(\App\Services\CloudCredentials::class)->references($account->id),
            'has_access_key' => (bool)$account->access_key_id_encrypted,
            'has_secret_key' => (bool)$account->access_key_secret_encrypted,
            'has_security_token' => (bool)$account->security_token_encrypted,
        ];
    }
}

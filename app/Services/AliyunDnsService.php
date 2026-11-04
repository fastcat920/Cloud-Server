<?php

namespace App\Services;

use App\Models\AliyunDnsAccount;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class AliyunDnsService
{
    const ENDPOINT = 'https://alidns.cn-hangzhou.aliyuncs.com/';
    const VERSION = '2015-01-09';

    public function listInstanceConfigs(AliyunDnsAccount $account)
    {
        return $this->request($account, 'ListCloudGtmInstanceConfigs', [
            'PageNumber' => 1,
            'PageSize' => 100,
            'AcceptLanguage' => 'zh-CN',
        ]);
    }

    public function fullInfo(AliyunDnsAccount $account, $instanceId, $configId)
    {
        return $this->request($account, 'DescribeCloudGtmInstanceConfigFullInfo', [
            'InstanceId' => $instanceId,
            'ConfigId' => $configId,
            'AcceptLanguage' => 'zh-CN',
        ]);
    }

    public function listAddressPools(AliyunDnsAccount $account)
    {
        return $this->request($account, 'ListCloudGtmAddressPools', [
            'PageNumber' => 1,
            'PageSize' => 100,
            'AcceptLanguage' => 'zh-CN',
        ]);
    }

    public function listAddresses(AliyunDnsAccount $account, $pageNumber = 1, $pageSize = 100)
    {
        return $this->request($account, 'ListCloudGtmAddresses', [
            'PageNumber' => $pageNumber,
            'PageSize' => $pageSize,
            'AcceptLanguage' => 'zh-CN',
        ]);
    }

    public function listMonitorTemplates(AliyunDnsAccount $account)
    {
        return $this->request($account, 'ListCloudGtmMonitorTemplates', [
            'PageNumber' => 1,
            'PageSize' => 100,
            'AcceptLanguage' => 'zh-CN',
        ]);
    }

    public function updateInstanceBasic(AliyunDnsAccount $account, array $parameters)
    {
        return $this->request($account, 'UpdateCloudGtmInstanceConfigBasic', $parameters);
    }

    public function updateInstanceStrategy(AliyunDnsAccount $account, array $parameters)
    {
        return $this->request($account, 'UpdateCloudGtmInstanceConfigLbStrategy', $parameters);
    }

    public function updateInstanceStatus(AliyunDnsAccount $account, $instanceId, $configId, $status)
    {
        return $this->request($account, 'UpdateCloudGtmInstanceConfigEnableStatus', [
            'InstanceId' => $instanceId,
            'ConfigId' => $configId,
            'EnableStatus' => $status,
        ]);
    }

    public function updatePoolStatus(AliyunDnsAccount $account, $poolId, $status)
    {
        return $this->request($account, 'UpdateCloudGtmAddressPoolEnableStatus', [
            'AddressPoolId' => $poolId,
            'EnableStatus' => $status,
        ]);
    }

    public function createPool(AliyunDnsAccount $account, array $parameters)
    {
        return $this->request($account, 'CreateCloudGtmAddressPool', $parameters);
    }

    public function updatePoolBasic(AliyunDnsAccount $account, array $parameters)
    {
        return $this->request($account, 'UpdateCloudGtmAddressPoolBasicConfig', $parameters);
    }

    public function updatePoolRemark(AliyunDnsAccount $account, $poolId, $remark)
    {
        return $this->request($account, 'UpdateCloudGtmAddressPoolRemark', [
            'AddressPoolId' => $poolId,
            'Remark' => $remark,
        ]);
    }

    public function deletePool(AliyunDnsAccount $account, $poolId)
    {
        return $this->request($account, 'DeleteCloudGtmAddressPool', [
            'AddressPoolId' => $poolId,
        ]);
    }

    public function replaceInstancePools(AliyunDnsAccount $account, $instanceId, $configId, array $pools)
    {
        return $this->request($account, 'ReplaceCloudGtmInstanceConfigAddressPool', [
            'InstanceId' => $instanceId,
            'ConfigId' => $configId,
            'ClientToken' => bin2hex(random_bytes(16)),
            'AddressPools' => array_values($pools),
        ]);
    }

    public function updatePoolStrategy(AliyunDnsAccount $account, array $parameters)
    {
        return $this->request($account, 'UpdateCloudGtmAddressPoolLbStrategy', $parameters);
    }

    public function updateAddressStatus(AliyunDnsAccount $account, $addressId, $status)
    {
        return $this->request($account, 'UpdateCloudGtmAddressEnableStatus', [
            'AddressId' => $addressId,
            'EnableStatus' => $status,
        ]);
    }

    public function createAddress(AliyunDnsAccount $account, array $parameters)
    {
        return $this->request($account, 'CreateCloudGtmAddress', $parameters);
    }

    public function updateAddress(AliyunDnsAccount $account, array $parameters)
    {
        return $this->request($account, 'UpdateCloudGtmAddress', $parameters);
    }

    public function updateAddressRemark(AliyunDnsAccount $account, $addressId, $remark)
    {
        return $this->request($account, 'UpdateCloudGtmAddressRemark', [
            'AddressId' => $addressId,
            'Remark' => $remark,
        ]);
    }

    public function updateAddressManualStatus(AliyunDnsAccount $account, $addressId, $mode, $status)
    {
        return $this->request($account, 'UpdateCloudGtmAddressManualAvailableStatus', [
            'AddressId' => $addressId,
            'AvailableMode' => $mode,
            'ManualAvailableStatus' => $mode === 'manual' ? $status : null,
        ]);
    }

    public function deleteAddress(AliyunDnsAccount $account, $addressId)
    {
        return $this->request($account, 'DeleteCloudGtmAddress', [
            'AddressId' => $addressId,
        ]);
    }

    public function replacePoolAddresses(AliyunDnsAccount $account, $poolId, array $addresses)
    {
        return $this->request($account, 'ReplaceCloudGtmAddressPoolAddress', [
            'AddressPoolId' => $poolId,
            // 与地址池替换接口相同，节点列表需要 Address 集合包装。
            'ClientToken' => bin2hex(random_bytes(16)),
            'Addresses' => array_values($addresses),
        ]);
    }

    public function request(AliyunDnsAccount $account, $action, array $parameters = [])
    {
        $account->assertCloudAccountEnabled();
        $accessKeyId = Crypt::decryptString($account->access_key_id_encrypted);
        $accessKeySecret = Crypt::decryptString($account->access_key_secret_encrypted);
        $parameters = $this->flattenParameters(array_merge([
            'Format' => 'JSON',
            'Version' => self::VERSION,
            'AccessKeyId' => $accessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Action' => $action,
            'AcceptLanguage' => 'zh-CN',
            'ClientToken' => bin2hex(random_bytes(16)),
        ], $parameters));
        if ($account->security_token_encrypted) {
            $parameters['SecurityToken'] = Crypt::decryptString($account->security_token_encrypted);
        }
        $parameters = array_filter($parameters, function ($value) {
            return $value !== null;
        });
        ksort($parameters);
        $canonical = [];
        foreach ($parameters as $key => $value) {
            $canonical[] = $this->percentEncode($key) . '=' . $this->percentEncode($value);
        }
        $canonicalQuery = implode('&', $canonical);
        $stringToSign = 'GET&%2F&' . $this->percentEncode($canonicalQuery);
        $parameters['Signature'] = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));

        try {
            $response = (new Client([
                'timeout' => 15,
                'connect_timeout' => 8,
                'http_errors' => false,
            ]))->get(self::ENDPOINT, ['query' => $parameters]);
        } catch (\Throwable $error) {
            throw new RuntimeException('无法连接阿里云云解析 DNS：' . $error->getMessage(), 0, $error);
        }

        $statusCode = $response->getStatusCode();
        $body = (string)$response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('阿里云返回了无法识别的数据（HTTP ' . $statusCode . '）');
        }
        if ($statusCode < 200 || $statusCode >= 300 || isset($decoded['Code'])) {
            $message = isset($decoded['Message']) ? $decoded['Message'] : '请求失败';
            $code = isset($decoded['Code']) ? $decoded['Code'] : 'HTTP ' . $statusCode;
            $requestId = isset($decoded['RequestId']) ? '，RequestId：' . $decoded['RequestId'] : '';
            throw new RuntimeException($code . '：' . $message . $requestId);
        }
        if (array_key_exists('Success', $decoded) && $decoded['Success'] !== true) {
            $requestId = isset($decoded['RequestId']) ? '，RequestId：' . $decoded['RequestId'] : '';
            throw new RuntimeException('阿里云未完成本次操作' . $requestId);
        }
        return $decoded;
    }

    private function percentEncode($value)
    {
        return str_replace('%7E', '~', rawurlencode((string)$value));
    }

    private function flattenParameters(array $parameters)
    {
        $flat = [];
        foreach ($parameters as $key => $value) {
            $this->flattenValue((string)$key, $value, $flat);
        }
        return $flat;
    }

    private function flattenValue($key, $value, array &$flat)
    {
        if (!is_array($value)) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $flat[$key] = $value;
            return;
        }
        $numeric = array_keys($value) === range(0, count($value) - 1);
        foreach ($value as $index => $child) {
            $childKey = $key . '.' . ($numeric ? $index + 1 : $index);
            $this->flattenValue($childKey, $child, $flat);
        }
    }
}

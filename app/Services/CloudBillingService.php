<?php

namespace App\Services;

use App\Models\CloudAccount;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class CloudBillingService
{
    const ALIYUN_CHINA_ENDPOINT = 'https://business.aliyuncs.com/';
    const ALIYUN_INTERNATIONAL_ENDPOINT = 'https://business.ap-southeast-1.aliyuncs.com/';
    const ALIYUN_VERSION = '2017-12-14';
    const ALIYUN_STS_CHINA_ENDPOINT = 'https://sts.cn-hangzhou.aliyuncs.com/';
    const ALIYUN_STS_INTERNATIONAL_ENDPOINT = 'https://sts.ap-southeast-1.aliyuncs.com/';
    const ALIYUN_STS_VERSION = '2015-04-01';
    const TENCENT_CHINA_HOST = 'billing.tencentcloudapi.com';
    const TENCENT_INTERNATIONAL_HOST = 'billing.intl.tencentcloudapi.com';
    const TENCENT_VERSION = '2018-07-09';
    const TENCENT_STS_VERSION = '2018-08-13';
    const UCLOUD_CHINA_ENDPOINT = 'https://api.ucloud.cn/';
    const UCLOUD_INTERNATIONAL_ENDPOINT = 'https://api.ucloud-global.com/';

    public function snapshot(CloudAccount $account)
    {
        $timezone = config('app.timezone', 'Asia/Shanghai');
        $now = Carbon::now($timezone);
        $currentMonth = $now->format('Y-m');
        $previousMonth = $now->copy()->subMonthNoOverflow()->format('Y-m');

        switch ($account->provider) {
            case 'aliyun':
                return $this->aliyunSnapshot($account, $currentMonth, $previousMonth);
            case 'tencent':
                return $this->tencentSnapshot($account, $currentMonth, $previousMonth);
            case 'ucloud':
                return $this->ucloudSnapshot($account, $currentMonth, $previousMonth);
            default:
                throw new RuntimeException('不支持的云账号类型');
        }
    }

    private function aliyunSnapshot(CloudAccount $account, $currentMonth, $previousMonth)
    {
        $balanceResponse = $this->aliyunRequest($account, 'QueryAccountBalance');
        $currentResponse = $this->aliyunRequest($account, 'QueryBillOverview', ['BillingCycle' => $currentMonth]);
        $previousResponse = $this->aliyunRequest($account, 'QueryBillOverview', ['BillingCycle' => $previousMonth]);
        $balanceData = $balanceResponse['Data'] ?? [];

        return [
            'account_id' => $this->aliyunCallerIdentity($account),
            'balance' => $this->number($balanceData['AvailableAmount'] ?? $balanceData['AvailableCashAmount'] ?? 0),
            'current_month_amount' => $this->sumAliyunBill($currentResponse),
            'previous_month_amount' => $this->sumAliyunBill($previousResponse),
            'currency' => strtoupper((string)($balanceData['Currency'] ?? $this->aliyunCurrency($currentResponse) ?? 'CNY')),
        ];
    }

    private function tencentSnapshot(CloudAccount $account, $currentMonth, $previousMonth)
    {
        $balanceResponse = $this->tencentRequest($account, 'DescribeAccountBalance', []);
        $currentResponse = $this->tencentRequest($account, 'DescribeBillSummary', [
            'Month' => $currentMonth,
            'GroupType' => 'business',
        ]);
        $previousResponse = $this->tencentRequest($account, 'DescribeBillSummary', [
            'Month' => $previousMonth,
            'GroupType' => 'business',
        ]);

        return [
            'account_id' => $this->tencentCallerIdentity($account),
            'balance' => $this->number($balanceResponse['Response']['RealBalance'] ?? $balanceResponse['Response']['Balance'] ?? 0) / 100,
            'current_month_amount' => $this->sumTencentBill($currentResponse),
            'previous_month_amount' => $this->sumTencentBill($previousResponse),
            'currency' => $this->site($account) === 'international' ? 'USD' : 'CNY',
        ];
    }

    private function ucloudSnapshot(CloudAccount $account, $currentMonth, $previousMonth)
    {
        $balanceResponse = $this->ucloudRequest($account, 'GetBalance');
        $balanceData = isset($balanceResponse['AccountInfo']) && is_array($balanceResponse['AccountInfo'])
            ? $balanceResponse['AccountInfo']
            : $balanceResponse;

        return [
            'account_id' => null,
            'balance' => $this->number($balanceData['AmountAvailable'] ?? $balanceData['Amount'] ?? 0),
            'current_month_amount' => $this->ucloudMonthAmount($account, $currentMonth),
            'previous_month_amount' => $this->ucloudMonthAmount($account, $previousMonth),
            'currency' => $this->site($account) === 'international' ? 'USD' : 'CNY',
        ];
    }

    public function resourceRequest(CloudAccount $account, $action, array $parameters = [], $region = null) {
        if (!$account->enabled) abort(422,'云账号已停用');
        if ($account->provider === 'aliyun') {
            $data=$this->jsonRequest('GET','https://ecs.aliyuncs.com/', ['query'=>$this->aliyunSignedParameters($account,$action,'2014-05-26',$parameters)],'阿里云 ECS');
            if (!empty($data['Code'])) throw new RuntimeException($data['Code'].'：'.($data['Message']??'请求失败'));
            return $data;
        }
        if ($account->provider === 'tencent') {
            $data=$this->tencentSignedRequest($account,$action,$parameters,'cvm','cvm.tencentcloudapi.com','2017-03-12','腾讯云 CVM',$region)['Response']??[];
            if (!empty($data['Error'])) throw new RuntimeException(($data['Error']['Code']??'Error').'：'.($data['Error']['Message']??'请求失败'));
            return $data;
        }
        return $this->ucloudRequest($account,$action,$parameters);
    }

    private function aliyunRequest(CloudAccount $account, $action, array $parameters = [])
    {
        $requestParameters = $this->aliyunSignedParameters($account, $action, self::ALIYUN_VERSION, $parameters);
        $response = $this->jsonResponse('GET', $this->aliyunEndpoint($account), ['query' => $requestParameters], '阿里云费用中心');
        $decoded = $response['data'];
        $code = (string)($decoded['Code'] ?? $decoded['code'] ?? '');
        $message = (string)($decoded['Message'] ?? $decoded['message'] ?? '');

        if (strcasecmp($code, 'AuthSiteFail') === 0) {
            throw new RuntimeException('阿里云账号站点不匹配，请确认选择的是中国站还是国际站');
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('阿里云费用中心请求失败（HTTP ' . $response['status'] . '）' . ($message ? '：' . $message : ''));
        }
        if (($decoded['Success'] ?? true) === false || ($code !== '' && !in_array($code, ['200', 'Success'], true))) {
            throw new RuntimeException(($code ?: 'AliyunError') . '：' . ($message ?: '阿里云费用中心请求失败'));
        }
        return $decoded;
    }

    private function aliyunCallerIdentity(CloudAccount $account)
    {
        try {
            $parameters = $this->aliyunSignedParameters($account, 'GetCallerIdentity', self::ALIYUN_STS_VERSION);
            $endpoint = $this->site($account) === 'international'
                ? self::ALIYUN_STS_INTERNATIONAL_ENDPOINT
                : self::ALIYUN_STS_CHINA_ENDPOINT;
            $response = $this->jsonResponse('GET', $endpoint, ['query' => $parameters], '阿里云身份服务');
            $accountId = $response['data']['AccountId'] ?? null;
            if ($response['status'] >= 200 && $response['status'] < 300 && $accountId) {
                return (string)$accountId;
            }
        } catch (\Throwable $error) {
            // 账号 ID 只是展示信息，身份接口异常不应阻断余额和账单同步。
        }
        return null;
    }

    private function aliyunSignedParameters(CloudAccount $account, $action, $version, array $parameters = [])
    {
        $requestParameters = array_merge([
            'Format' => 'JSON',
            'Version' => $version,
            'AccessKeyId' => $this->accessKeyId($account),
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'Action' => $action,
        ], $parameters);
        $token = $this->securityToken($account);
        if ($token !== '') {
            $requestParameters['SecurityToken'] = $token;
        }
        ksort($requestParameters);
        $canonical = [];
        foreach ($requestParameters as $key => $value) {
            $canonical[] = $this->aliyunEncode($key) . '=' . $this->aliyunEncode($value);
        }
        $canonicalQuery = implode('&', $canonical);
        $stringToSign = 'GET&%2F&' . $this->aliyunEncode($canonicalQuery);
        $requestParameters['Signature'] = base64_encode(hash_hmac('sha1', $stringToSign, $this->accessKeySecret($account) . '&', true));
        return $requestParameters;
    }

    private function tencentRequest(CloudAccount $account, $action, array $payload)
    {
        $decoded = $this->tencentSignedRequest(
            $account,
            $action,
            $payload,
            'billing',
            $this->tencentHost($account),
            self::TENCENT_VERSION,
            '腾讯云费用中心'
        );
        if (!empty($decoded['Response']['Error'])) {
            $error = $decoded['Response']['Error'];
            if (($error['Code'] ?? '') === 'AuthFailure.SignatureFailure') {
                throw new RuntimeException('腾讯云签名验证失败，请检查账号站点、SecretId 和 SecretKey');
            }
            throw new RuntimeException(($error['Code'] ?? 'TencentError') . '：' . ($error['Message'] ?? '腾讯云费用中心请求失败'));
        }
        return $decoded;
    }

    private function tencentCallerIdentity(CloudAccount $account)
    {
        $endpoints = $this->site($account) === 'international'
            ? [['host' => 'sts.intl.tencentcloudapi.com', 'region' => 'ap-singapore']]
            : [['host' => 'sts.tencentcloudapi.com', 'region' => 'ap-guangzhou']];
        foreach ($endpoints as $endpoint) {
            try {
                $decoded = $this->tencentSignedRequest(
                    $account,
                    'GetCallerIdentity',
                    [],
                    'sts',
                    $endpoint['host'],
                    self::TENCENT_STS_VERSION,
                    '腾讯云身份服务',
                    $endpoint['region']
                );
                if (empty($decoded['Response']['Error'])) {
                    $accountId = $decoded['Response']['AccountId'] ?? $decoded['Response']['AccountID'] ?? null;
                    if ($accountId) {
                        return (string)$accountId;
                    }
                }
            } catch (\Throwable $error) {
                // 账号 ID 只是展示信息，身份接口异常不应阻断余额和账单同步。
            }
        }
        return null;
    }

    private function tencentSignedRequest(CloudAccount $account, $action, array $payload, $service, $host, $version, $label, $region = null)
    {
        $timestamp = time();
        $date = gmdate('Y-m-d', $timestamp);
        $payloadJson = json_encode((object)$payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $canonicalHeaders = "content-type:application/json; charset=utf-8\n" .
            'host:' . $host . "\n" .
            'x-tc-action:' . strtolower($action) . "\n";
        $signedHeaders = 'content-type;host;x-tc-action';
        $canonicalRequest = "POST\n/\n\n" . $canonicalHeaders . "\n" . $signedHeaders . "\n" . hash('sha256', $payloadJson);
        $scope = $date . '/' . $service . '/tc3_request';
        $stringToSign = "TC3-HMAC-SHA256\n" . $timestamp . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);
        $secretDate = hash_hmac('sha256', $date, 'TC3' . $this->accessKeySecret($account), true);
        $secretService = hash_hmac('sha256', $service, $secretDate, true);
        $secretSigning = hash_hmac('sha256', 'tc3_request', $secretService, true);
        $signature = hash_hmac('sha256', $stringToSign, $secretSigning);
        $authorization = 'TC3-HMAC-SHA256 Credential=' . $this->accessKeyId($account) . '/' . $scope .
            ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        $headers = [
            'Authorization' => $authorization,
            'Content-Type' => 'application/json; charset=utf-8',
            'Host' => $host,
            'X-TC-Action' => $action,
            'X-TC-Timestamp' => $timestamp,
            'X-TC-Version' => $version,
        ];
        if ($region !== null) {
            $headers['X-TC-Region'] = $region;
        }
        $token = $this->securityToken($account);
        if ($token !== '') {
            $headers['X-TC-Token'] = $token;
        }
        return $this->jsonRequest('POST', 'https://' . $host . '/', [
            'headers' => $headers,
            'body' => $payloadJson,
        ], $label);
    }

    private function ucloudRequest(CloudAccount $account, $action, array $parameters = [])
    {
        $parameters = array_merge(['Action' => $action, 'PublicKey' => $this->accessKeyId($account)], $parameters);
        ksort($parameters);
        $signing = '';
        foreach ($parameters as $key => $value) {
            $signing .= $key . $this->ucloudValue($value);
        }
        $parameters['Signature'] = sha1($signing . $this->accessKeySecret($account));
        $decoded = $this->jsonRequest('GET', $this->ucloudEndpoint($account), ['query' => $parameters], 'UCloud 财务中心');
        if ((int)($decoded['RetCode'] ?? -1) !== 0) {
            if ((int)($decoded['RetCode'] ?? -1) === 171) {
                throw new RuntimeException('UCloud 签名验证失败，请检查账号站点、公钥和私钥');
            }
            throw new RuntimeException('UCloud ' . ($decoded['RetCode'] ?? 'Error') . '：' . ($decoded['Message'] ?? '财务中心请求失败'));
        }
        return $decoded;
    }

    private function ucloudMonthAmount(CloudAccount $account, $month)
    {
        $paid = $this->ucloudRequest($account, 'ListUBillOverview', [
            'BillingCycle' => $month,
            'Dimension' => 'product',
            'HideUnpaid' => 1,
        ]);
        $unpaid = $this->ucloudRequest($account, 'ListUBillOverview', [
            'BillingCycle' => $month,
            'Dimension' => 'product',
            'HideUnpaid' => 0,
        ]);
        return $this->number($paid['TotalPaidAmount'] ?? 0) + $this->number($unpaid['TotalUnpaidAmount'] ?? 0);
    }

    private function sumAliyunBill(array $response)
    {
        $items = $response['Data']['Items']['Item'] ?? [];
        if (!is_array($items)) {
            return 0.0;
        }
        if ($this->isAssociative($items)) {
            $items = [$items];
        }
        return array_reduce($items, function ($total, $item) {
            return $total + $this->number(is_array($item) ? ($item['PretaxAmount'] ?? $item['AfterTaxAmount'] ?? 0) : 0);
        }, 0.0);
    }

    private function aliyunCurrency(array $response)
    {
        $items = $response['Data']['Items']['Item'] ?? [];
        if ($this->isAssociative($items)) {
            return $items['Currency'] ?? null;
        }
        return isset($items[0]['Currency']) ? $items[0]['Currency'] : null;
    }

    private function sumTencentBill(array $response)
    {
        if ((int)($response['Response']['Ready'] ?? 1) === 0) {
            throw new RuntimeException('腾讯云账单正在初始化，请稍后刷新');
        }
        $items = $response['Response']['SummaryDetail'] ?? [];
        if (!is_array($items)) {
            return 0.0;
        }
        return array_reduce($items, function ($total, $item) {
            return $total + $this->number(is_array($item) ? ($item['RealTotalCost'] ?? 0) : 0);
        }, 0.0);
    }

    private function jsonRequest($method, $url, array $options, $label)
    {
        $response = $this->jsonResponse($method, $url, $options, $label);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $decoded = $response['data'];
            $message = $decoded['Message'] ?? $decoded['message'] ?? $decoded['Response']['Error']['Message'] ?? '';
            throw new RuntimeException($label . '请求失败（HTTP ' . $response['status'] . '）' . ($message ? '：' . $message : ''));
        }
        return $response['data'];
    }

    private function jsonResponse($method, $url, array $options, $label)
    {
        try {
            $response = (new Client([
                'timeout' => 25,
                'connect_timeout' => 8,
                'http_errors' => false,
            ]))->request($method, $url, $options);
        } catch (\Throwable $error) {
            throw new RuntimeException('无法连接' . $label . '：' . $error->getMessage(), 0, $error);
        }
        $status = $response->getStatusCode();
        $decoded = json_decode((string)$response->getBody(), true);
        if (!is_array($decoded)) {
            throw new RuntimeException($label . '返回了无法识别的数据（HTTP ' . $status . '）');
        }
        return ['status' => $status, 'data' => $decoded];
    }

    private function accessKeyId(CloudAccount $account)
    {
        return Crypt::decryptString($account->access_key_id_encrypted);
    }

    private function accessKeySecret(CloudAccount $account)
    {
        return Crypt::decryptString($account->access_key_secret_encrypted);
    }

    private function securityToken(CloudAccount $account)
    {
        return $account->security_token_encrypted
            ? Crypt::decryptString($account->security_token_encrypted)
            : '';
    }

    private function site(CloudAccount $account)
    {
        return $account->site === 'international' ? 'international' : 'china';
    }

    private function aliyunEndpoint(CloudAccount $account)
    {
        return $this->site($account) === 'international'
            ? self::ALIYUN_INTERNATIONAL_ENDPOINT
            : self::ALIYUN_CHINA_ENDPOINT;
    }

    private function tencentHost(CloudAccount $account)
    {
        return $this->site($account) === 'international'
            ? self::TENCENT_INTERNATIONAL_HOST
            : self::TENCENT_CHINA_HOST;
    }

    private function ucloudEndpoint(CloudAccount $account)
    {
        return $this->site($account) === 'international'
            ? self::UCLOUD_INTERNATIONAL_ENDPOINT
            : self::UCLOUD_CHINA_ENDPOINT;
    }

    private function aliyunEncode($value)
    {
        return str_replace('%7E', '~', rawurlencode((string)$value));
    }

    private function ucloudValue($value)
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string)$value;
    }

    private function number($value)
    {
        return is_numeric($value) ? (float)$value : 0.0;
    }

    private function isAssociative(array $value)
    {
        if ($value === []) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }
}

<?php

declare(strict_types=1);

namespace CloudDesk;

final class CloudResponseError extends \RuntimeException
{
    public function __construct(string $message, public readonly string $providerCode='', public readonly string $requestId='', public readonly int $httpStatus=200) { parent::__construct($message); }
}
final class Http
{
    public static $transport = null;
    public static array $lastTiming = [];
    public static function request(string $method, string $url, array $headers = [], string $body = '', int $limit = 33554432, int $timeout = 60, bool $reuse = false): array
    {
        self::$lastTiming = [];
        if (self::$transport) {
            return (self::$transport)($method, $url, $headers, $body);
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('请在宝塔 PHP 设置中启用 curl 扩展');
        }
        // Request-local handles retain connections; reset all request options between transfers.
        static $handles = [];
        $origin = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).':'.(parse_url($url, PHP_URL_PORT) ?: 443);
        if ($reuse && isset($handles[$origin])) { $handle=$handles[$origin];curl_reset($handle); }
        else $handle=curl_init();
        if ($reuse) {
            $handles[$origin]=$handle;curl_setopt($handle,CURLOPT_ENCODING,'');
            if (ini_get('curl.cainfo')!=='') curl_setopt($handle,CURLOPT_CAINFO,ini_get('curl.cainfo'));
        }
        $bytes = '';
        $size = 0;
        $tooLarge = false;
        curl_setopt_array($handle, [CURLOPT_URL => $url,CURLOPT_CUSTOMREQUEST => $method,CURLOPT_HTTPHEADER => array_map(fn ($k) => $k.': '.$headers[$k], array_keys($headers)),CURLOPT_CONNECTTIMEOUT => 8,CURLOPT_TIMEOUT => $timeout,CURLOPT_FOLLOWLOCATION => false,CURLOPT_PATH_AS_IS => true,CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER => true,CURLOPT_SSL_VERIFYHOST => 2,CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$bytes, &$size, &$tooLarge, $limit) {
            $size += strlen($chunk);
            if ($size > $limit) {
                $tooLarge = true;
                return 0;
            }
            $bytes .= $chunk;
            return strlen($chunk);
        }]);
        if ($body !== '' || in_array($method, ['POST','PUT','PATCH'], true)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle);
        if ($reuse) {
            $info = curl_getinfo($handle);
            $tls = defined('CURLINFO_APPCONNECT_TIME_T') ? (int)curl_getinfo($handle, CURLINFO_APPCONNECT_TIME_T) / 1000 : 0;
            self::$lastTiming = [
                'dns'=>(float)($info['namelookup_time'] ?? 0)*1000,
                'tcp'=>(float)($info['connect_time'] ?? 0)*1000,
                'tls'=>$tls,
                'first_byte'=>(float)($info['starttransfer_time'] ?? 0)*1000,
                'http'=>$status,
                'curl'=>$error,
            ];
        }
        if (!$reuse || $ok===false || $error || $tooLarge) { if ($reuse) unset($handles[$origin]);curl_close($handle); }
        if ($tooLarge) {
            throw new \RuntimeException('文件超过当前 32 MB 下载上限，请使用云厂商工具下载');
        }
        if ($error === 28) throw new \RuntimeException('云服务请求超时（28）。若正在提交变更，请先刷新核对云端状态再重试');
        if ($ok === false || $error) {
            throw new \RuntimeException('云服务连接失败，请检查网络与地域（'.$error.'）');
        }
        return ['status' => $status,'body' => $bytes];
    }
    public static function json(string $method, string $url, array $headers = [], array|string|null $body = null, int $timeout = 60, bool $reuse = false): array
    {
        if (is_array($body)) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $response = self::request($method, $url, $headers, $body ?? '', timeout:$timeout,reuse:$reuse);
        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            throw new \RuntimeException('云服务返回格式错误（HTTP '.$response['status'].'）');
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new CloudResponseError((string)($data['Message'] ?? $data['message'] ?? $data['errors'][0]['message'] ?? '云服务请求失败').'（HTTP '.$response['status'].'）',(string)($data['Code'] ?? $data['code'] ?? $data['errors'][0]['code'] ?? ''),(string)($data['RequestId'] ?? $data['requestId'] ?? ''),$response['status']);
        }
        return $data;
    }
}
final class Cloud
{
    public function __construct(private Security $security)
    {
    }
    public static function dnsTtlOptions(array $info): array
    {
        $minimum=max(1,min(86400,(int)($info['MinTtl'] ?? 600)));
        $values=[];
        $read=function(mixed $value,int $depth=0) use (&$read,&$values,$minimum): void {
            if ($depth>4) return;
            if (is_array($value)) { foreach ($value as $item) $read($item,$depth+1);return; }
            if (is_string($value) && str_starts_with(trim($value),'[')) { $decoded=json_decode($value,true);if(is_array($decoded))$read($decoded,$depth+1);return; }
            if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int)$value>=$minimum && (int)$value<=86400) $values[(int)$value]=(int)$value;
        };
        $read($info['AvailableTtls']['AvailableTtl'] ?? []);
        if (!$values) $read([$minimum,600,1800,3600,43200,86400]);
        return array_values($values);
    }
    public function credentials(array $account): array
    {
        if (!($account['enabled'] ?? false)) {
            throw new \RuntimeException('此云账号已停用');
        }
        return array_map(fn ($v) => $this->security->decrypt($v), $account['credentials']);
    }
    public function rpc(array $account, string $action, array $params = [], string $region = '', string $service = 'ecs', string $method = 'GET', int $timeout = 60, bool $reuse = false, bool $queryPost = false): array
    {
        $params = array_diff_key($params, array_fill_keys(['Action','Format','Version','AccessKeyId','PublicKey','SecurityToken','Signature','SignatureMethod','SignatureVersion','SignatureNonce','Timestamp'], true));
        $keys = $this->credentials($account);
        $id = $keys['key'] ?? '';
        $secret = $keys['secret'] ?? '';
        $token = $keys['token'] ?? '';
        $provider = $account['provider'];
        if ($provider === 'aliyun') {
            $versions = ['ecs' => '2014-05-26','alidns' => '2015-01-09','business' => '2017-12-14','sts' => '2015-04-01'];
            $host = ['ecs' => 'ecs.aliyuncs.com','alidns' => 'alidns.cn-hangzhou.aliyuncs.com','business' => ($account['site'] ?? 'china') === 'international' ? 'business.ap-southeast-1.aliyuncs.com' : 'business.aliyuncs.com','sts' => 'sts.aliyuncs.com'][$service] ?? throw new \RuntimeException('服务无效');
            $query = array_merge(['Format' => 'JSON','Version' => $versions[$service],'AccessKeyId' => $id,'SignatureMethod' => 'HMAC-SHA1','SignatureVersion' => '1.0','SignatureNonce' => bin2hex(random_bytes(16)),'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),'Action' => $action], $params);
            if ($token) {
                $query['SecurityToken'] = $token;
            }
            // RPC list values use numbered dot notation, not JSON query strings.
            $flat = [];
            $flatten = function ($value, $prefix) use (&$flatten, &$flat) {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $flatten($v, $prefix.'.'.(is_int($k) ? $k + 1 : $k));
                    }
                } elseif ($value !== null) {
                    $flat[$prefix] = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
                }
            };
            foreach ($query as $k => $v) {
                $flatten($v, $k);
            }
            ksort($flat);
            $canonical = http_build_query($flat, '', '&', PHP_QUERY_RFC3986);
            $flat['Signature'] = base64_encode(hash_hmac('sha1', $method.'&%2F&'.rawurlencode($canonical), $secret.'&', true));
            $encoded = http_build_query($flat, '', '&', PHP_QUERY_RFC3986);
            // The generated GTM SDK places RPC parameters in the query even for POST.
            $data = $method === 'POST'
                ? Http::json('POST', 'https://'.$host.'/'.($queryPost?'?'.$encoded:''), ['Content-Type'=>'application/x-www-form-urlencoded'], $queryPost?'':$encoded, $timeout, $reuse)
                : Http::json('GET', 'https://'.$host.'/?'.$encoded, timeout:$timeout,reuse:$reuse);
            if (isset($data['Code']) && !in_array((string)$data['Code'], ['200','Success'], true)) {
                throw new CloudResponseError($data['Code'].'：'.($data['Message'] ?? '请求失败'),(string)$data['Code'],(string)($data['RequestId'] ?? ''),200);
            }
            if (($data['Success'] ?? true) === false) {
                throw new CloudResponseError($data['Message'] ?? '请求失败',(string)($data['Code'] ?? ''),(string)($data['RequestId'] ?? ''),200);
            }
            return $data;
        }
        if ($provider === 'tencent') {
            $billing = $service === 'billing';
            $service = $billing ? 'billing' : 'cvm';
            $host = $service.'.tencentcloudapi.com';
            if ($billing && ($account['site'] ?? 'china') === 'international') {
                $host = 'billing.intl.tencentcloudapi.com';
            }
            $timestamp = time();
            $date = gmdate('Y-m-d', $timestamp);
            $payload = json_encode((object)$params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $scope = $date.'/'.$service.'/tc3_request';
            $canonical = "POST\n/\n\ncontent-type:application/json; charset=utf-8\nhost:".$host."\nx-tc-action:".strtolower($action)."\n\ncontent-type;host;x-tc-action\n".hash('sha256', $payload);
            $signKey = hash_hmac('sha256', $date, 'TC3'.$secret, true);
            foreach ([$service,'tc3_request'] as $part) {
                $signKey = hash_hmac('sha256', $part, $signKey, true);
            }
            $signature = hash_hmac('sha256', "TC3-HMAC-SHA256\n$timestamp\n$scope\n".hash('sha256', $canonical), $signKey);
            $headers = ['Host' => $host,'Content-Type' => 'application/json; charset=utf-8','X-TC-Action' => $action,'X-TC-Timestamp' => (string)$timestamp,'X-TC-Version' => $billing ? '2018-07-09' : '2017-03-12','Authorization' => 'TC3-HMAC-SHA256 Credential='.$id.'/'.$scope.', SignedHeaders=content-type;host;x-tc-action, Signature='.$signature];
            if ($region) {
                $headers['X-TC-Region'] = $region;
            }
            if ($token) {
                $headers['X-TC-Token'] = $token;
            }
            $data = Http::json('POST', 'https://'.$host.'/', $headers, $payload)['Response'] ?? [];
            if (isset($data['Error'])) {
                throw new \RuntimeException(($data['Error']['Code'] ?? 'Error').'：'.($data['Error']['Message'] ?? '请求失败'));
            }
            return $data;
        }
        if ($provider !== 'ucloud') {
            throw new \RuntimeException('不支持此云厂商');
        }
        $query = array_merge(['Action' => $action,'PublicKey' => $id], $params);
        ksort($query);
        $sign = '';
        foreach ($query as $k => $v) {
            $sign .= $k.(is_array($v) ? json_encode($v, JSON_UNESCAPED_SLASHES) : $v);
        }
        $query['Signature'] = sha1($sign.$secret);
        $host = ($account['site'] ?? 'china') === 'international' ? 'api.ucloud-global.com' : 'api.ucloud.cn';
        $data = Http::json('GET', 'https://'.$host.'/?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        if ((int)($data['RetCode'] ?? -1) !== 0) {
            throw new \RuntimeException(($data['Message'] ?? 'UCloud 请求失败').'（'.($data['RetCode'] ?? 'unknown').'）');
        }
        return $data;
    }
    private function number(mixed $value): float
    {
        return (float)str_replace([',',' '], '', (string)$value);
    }
    public function billing(array $account): array
    {
        $month = gmdate('Y-m');
        $previous = gmdate('Y-m', strtotime('first day of last month'));
        if ($account['provider'] === 'aliyun') {
            $balance = $this->rpc($account, 'QueryAccountBalance', [], service:'business')['Data'] ?? [];
            $amounts = [];
            foreach ([$month,$previous] as $period) {
                $bill = $this->rpc($account, 'QueryBillOverview', ['BillingCycle' => $period], service:'business');
                $items = $bill['Data']['Items']['Item'] ?? [];
                if (isset($items['PretaxAmount']) || isset($items['AfterTaxAmount'])) {
                    $items = [$items];
                }
                $amounts[] = array_sum(array_map(fn ($r) => $this->number($r['PretaxAmount'] ?? $r['AfterTaxAmount'] ?? 0), $items));
            }
            return ['balance' => $this->number($balance['AvailableAmount'] ?? $balance['AvailableCashAmount'] ?? 0),'currency' => $balance['Currency'] ?? 'CNY','current' => $amounts[0],'previous' => $amounts[1],'synced_at' => time()];
        }
        if ($account['provider'] === 'tencent') {
            $balance = $this->rpc($account, 'DescribeAccountBalance', [], service:'billing');
            $amounts = [];
            foreach ([$month,$previous] as $period) {
                $bill = $this->rpc($account, 'DescribeBillSummary', ['Month' => $period,'GroupType' => 'business'], service:'billing');
                if ((int)($bill['Ready'] ?? 1) === 0) {
                    throw new \RuntimeException('腾讯云账单正在初始化，请稍后重试');
                }
                $amounts[] = array_sum(array_map(fn ($r) => $this->number($r['RealTotalCost'] ?? 0), $bill['SummaryDetail'] ?? []));
            }
            return ['balance' => $this->number($balance['RealBalance'] ?? $balance['Balance'] ?? 0) / 100,'currency' => ($account['site'] ?? 'china') === 'international' ? 'USD' : 'CNY','current' => $amounts[0],'previous' => $amounts[1],'synced_at' => time()];
        }
        $balance = $this->rpc($account, 'GetBalance');
        $amounts = [];
        foreach ([$month,$previous] as $period) {
            $paid = $this->rpc($account, 'ListUBillOverview', ['BillingCycle' => $period,'Dimension' => 'product','HideUnpaid' => 1]);
            $unpaid = $this->rpc($account, 'ListUBillOverview', ['BillingCycle' => $period,'Dimension' => 'product','HideUnpaid' => 0]);
            $amounts[] = $this->number($paid['TotalPaidAmount'] ?? 0) + $this->number($unpaid['TotalUnpaidAmount'] ?? 0);
        }
        return ['balance' => $this->number($balance['AccountInfo']['AmountAvailable'] ?? $balance['AccountInfo']['Amount'] ?? $balance['AmountAvailable'] ?? $balance['Amount'] ?? 0),'currency' => ($account['site'] ?? 'china') === 'international' ? 'USD' : 'CNY','current' => $amounts[0],'previous' => $amounts[1],'synced_at' => time()];
    }
    public function regions(array $account): array
    {
        $data = $this->rpc($account, $account['provider'] === 'ucloud' ? 'GetRegion' : 'DescribeRegions');
        $items = $account['provider'] === 'aliyun' ? ($data['Regions']['Region'] ?? []) : ($data['RegionSet'] ?? $data['Regions'] ?? []);
        $rows = [];
        foreach ($items as $r) {
            $id = $r['RegionId'] ?? $r['Region'] ?? '';
            if ($id) {
                $rows[$id] = ['id' => $id,'name' => $r['LocalName'] ?? $r['RegionName'] ?? $id];
            }
        }
        return array_values($rows);
    }
    public function instances(array $account, string $region, int $page, string $project = ''): array
    {
        $p = $account['provider'];
        $params = $p === 'aliyun' ? ['RegionId' => $region,'PageNumber' => $page,'PageSize' => 20] : ($p === 'tencent' ? ['Offset' => ($page - 1) * 20,'Limit' => 20] : array_filter(['Region' => $region,'Offset' => ($page - 1) * 20,'Limit' => 20,'ProjectId' => $project], fn ($v) => $v !== ''));
        $data = $this->rpc($account, $p === 'ucloud' ? 'DescribeUHostInstance' : 'DescribeInstances', $params, $region);
        $items = $data['Instances']['Instance'] ?? $data['InstanceSet'] ?? $data['UHostSet'] ?? [];
        $rows = [];
        foreach ($items as $r) {
            $ip = $r['PublicIpAddress']['IpAddress'] ?? $r['PublicIpAddresses'] ?? [];
            if ($p === 'ucloud') {
                $ip = array_column(array_filter($r['IPSet'] ?? [], fn ($i) => ($i['Type'] ?? '') !== 'Private'), 'IP');
            }
            $rows[] = ['id' => $r['InstanceId'] ?? $r['UHostId'],'name' => $r['InstanceName'] ?? $r['Name'] ?? '未命名','status' => $r['Status'] ?? $r['InstanceState'] ?? $r['State'] ?? '','ip' => implode(', ', $ip),'private_ip' => implode(', ', $p === 'ucloud' ? array_column(array_filter($r['IPSet'] ?? [], fn ($i) => ($i['Type'] ?? '') === 'Private'), 'IP') : ($r['VpcAttributes']['PrivateIpAddress']['IpAddress'] ?? $r['PrivateIpAddresses'] ?? $r['InnerIpAddress']['IpAddress'] ?? [])),'cpu' => $r['Cpu'] ?? $r['CPU'] ?? null,'memory' => isset($r['Memory']) ? $r['Memory'] / ($p === 'tencent' ? 1 : 1024) : null,'zone' => $r['ZoneId'] ?? $r['Placement']['Zone'] ?? $r['Zone'] ?? '','expires' => $r['ExpiredTime'] ?? $r['ExpireTime'] ?? null];
        }
        return ['items' => $rows,'total' => (int)($data['TotalCount'] ?? count($rows))];
    }
    public function instanceAction(array $account, string $region, string $id, string $operation, string $project = ''): array
    {
        $actions = ['aliyun' => ['start' => 'StartInstance','stop' => 'StopInstance','reboot' => 'RebootInstance'],'tencent' => ['start' => 'StartInstances','stop' => 'StopInstances','reboot' => 'RebootInstances'],'ucloud' => ['start' => 'StartUHostInstance','stop' => 'StopUHostInstance','reboot' => 'RebootUHostInstance']];
        $action = $actions[$account['provider']][$operation] ?? throw new \RuntimeException('操作无效');
        $params = match($account['provider']) {
            'aliyun' => ['RegionId' => $region,'InstanceId' => $id],'tencent' => ['InstanceIds' => [$id]],default => array_filter(['Region' => $region,'UHostId' => $id,'ProjectId' => $project], fn ($v) => $v !== '')
        };
        return $this->rpc($account, $action, $params, $region);
    }
    public function cloudflare(array $account, string $method, string $path, array $data = []): array
    {
        $keys = $this->credentials($account);
        $url = 'https://api.cloudflare.com/client/v4'.$path;
        $headers = ['Authorization' => 'Bearer '.preg_replace('/^(?:Bearer\s+)/i', '', trim($keys['token'] ?? ''))];
        if ($method === 'GET' && $data) {
            $url .= '?'.http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        }
        $response = Http::json($method, $url, $headers, $method === 'GET' ? null : ($data ?: null));
        if (empty($response['success'])) {
            throw new \RuntimeException(implode('；', array_column($response['errors'] ?? [], 'message')) ?: 'Cloudflare 请求失败');
        }
        return $response;
    }
}

<?php

declare(strict_types=1);
namespace CloudDesk;

final class Gtm
{
    private const KINDS = [
        'list'=>['ListCloudGtmInstanceConfigs','InstanceConfigs','InstanceConfig','DescribeCloudGtmInstanceConfigFullInfo','ConfigId'],
        'pools'=>['ListCloudGtmAddressPools','AddressPools','AddressPool','DescribeCloudGtmAddressPool','AddressPoolId'],
        'addresses'=>['ListCloudGtmAddresses','Addresses','Address','DescribeCloudGtmAddress','AddressId'],
        'monitors'=>['ListCloudGtmMonitorTemplates','Templates','Template','DescribeCloudGtmMonitorTemplate','TemplateId'],
        'instances'=>['ListCloudGtmInstances','Instances','Instance'],
    ];
    public function __construct(private Cloud $cloud, private Store $store, private Security $security) {}
    public static function catalog(): array { static $catalog; return $catalog ??= json_decode(file_get_contents(__DIR__.'/gtm-operations.json'),true,64,JSON_THROW_ON_ERROR); }
    private function call(array $account,string $operation,array $params=[]): array
    {
        foreach (self::catalog()[$operation]['json'] ?? [] as $field) {
            if (isset($params[$field]) && is_array($params[$field])) $params[$field]=json_encode($params[$field],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }
        return $this->cloud->rpc($account,$operation,['AcceptLanguage'=>'zh-CN']+$params,service:'alidns',method:isset(self::catalog()[$operation])?'POST':'GET');
    }
    public function listing(array $account,string $kind,int $page=1,array $filters=[]): array
    {
        $map=self::KINDS[$kind] ?? throw new ApiError('GTM 资源类型无效');
        $data=$this->call($account,$map[0],['PageNumber'=>max(1,$page),'PageSize'=>100]+$filters);
        $items=$data[$map[1]][$map[2]] ?? [];
        if (!is_array($items) || ($items && !array_is_list($items))) throw new ApiError('GTM 列表返回格式异常');
        return ['items'=>$items,'total'=>(int)($data['TotalItems'] ?? count($items)),'page'=>$page,'request_id'=>$data['RequestId'] ?? null];
    }
    private function all(array $account,string $kind,array $filters=[]): array
    {
        $items=[];
        for ($page=1;$page<=50;$page++) {
            $data=$this->listing($account,$kind,$page,$filters);$items=array_merge($items,$data['items']);
            if (count($items)>=$data['total'] || !$data['items']) return $items;
        }
        throw new ApiError('GTM 资源超过当前选择器上限，请在阿里云控制台处理');
    }
    public static function fingerprint(array $resource): string
    {
        $clean=function(mixed $value) use (&$clean): mixed {
            if (!is_array($value)) return $value;
            foreach (['RequestId','CreateTime','CreateTimestamp','UpdateTime','UpdateTimestamp','HealthStatus','AvailableStatus','MonitorStatus','SeqNonPreemptiveSchedule'] as $key) unset($value[$key]);
            if (!array_is_list($value)) ksort($value);
            foreach ($value as &$child) $child=$clean($child);
            return $value;
        };
        return hash('sha256',json_encode($clean($resource),JSON_THROW_ON_ERROR));
    }
    public function detail(array $account,string $kind,string $id): array
    {
        $map=self::KINDS[$kind] ?? throw new ApiError('GTM 资源类型无效');
        if (!isset($map[3]) || $id==='') throw new ApiError('缺少 GTM 资源 ID');
        $resource=$this->call($account,$map[3],[$map[4]=>$id]);
        if (($resource[$map[4]] ?? null)!==$id) throw new ApiError('GTM 详情返回的资源标识不匹配');
        return ['resource'=>$resource,'token'=>self::fingerprint($resource),'kind'=>$kind];
    }
    public function references(array $account,string $kind,string $id): array
    {
        if ($kind==='pools') {
            $data=$this->call($account,'DescribeCloudGtmAddressPoolReference',['AddressPoolId'=>$id]);
            return $data['InstanceConfigs']['InstanceConfig'] ?? [];
        }
        if ($kind==='addresses') {
            $data=$this->call($account,'DescribeCloudGtmAddressReference',['AddressId'=>$id]);
            return $data['AddressPools']['AddressPool'] ?? [];
        }
        if ($kind==='monitors') return $this->all($account,'addresses',['MonitorTemplateId'=>$id]);
        return [];
    }
    public function options(array $account): array
    {
        $result=['errors'=>[]];
        foreach (array_keys(self::KINDS) as $kind) {
            try { $result[$kind]=$this->all($account,$kind); }
            catch (\RuntimeException $error) { $result[$kind]=[];$result['errors'][$kind]=$error->getMessage(); }
        }
        foreach (['nodes'=>'ListCloudGtmMonitorNodes','lines'=>'DescribeCloudGtmSystemLines'] as $key=>$operation) {
            try { $result[$key]=$this->call($account,$operation); }
            catch (\RuntimeException $error) { $result[$key]=[];$result['errors'][$key]=$error->getMessage(); }
        }
        return $result;
    }
    private static function validateValue(mixed $value,array $schema,string $path): void
    {
        $type=$schema['type'];
        $valid=match($type) {'string'=>is_string($value),'integer'=>is_int($value),'boolean'=>is_bool($value),'array'=>is_array($value)&&array_is_list($value),'object'=>is_array($value),default=>false};
        if (!$valid) throw new ApiError('字段类型错误：'.$path);
        if (isset($schema['enum']) && !in_array($value,$schema['enum'],true)) throw new ApiError('字段选项无效：'.$path);
        if (is_int($value) && ($value<($schema['minimum'] ?? PHP_INT_MIN) || $value>($schema['maximum'] ?? PHP_INT_MAX))) throw new ApiError('字段数值超出范围：'.$path);
        if (is_string($value) && strlen($value)>4000) throw new ApiError('字段过长：'.$path);
        if ($type==='array') {
            if (count($value)>($schema['maxItems'] ?? 100)) throw new ApiError('列表项目过多：'.$path);
            foreach ($value as $i=>$child) self::validateValue($child,$schema['items'],$path.'.'.$i);
        }
        if ($type==='object') self::validateFields($value,$schema['properties'] ?? [],$path);
    }
    private static function validateFields(array $params,array $fields,string $path=''): void
    {
        foreach ($params as $key=>$value) {
            if (!isset($fields[$key])) throw new ApiError('不支持的字段：'.$path.$key);
            self::validateValue($value,$fields[$key],$path.$key);
        }
        foreach ($fields as $key=>$field) if (($field['required'] ?? false) && (!array_key_exists($key,$params) || $params[$key]==='')) throw new ApiError('缺少字段：'.$path.$key);
    }
    public function preview(array $account,string $operation,array $params,?string $expected): array
    {
        $spec=self::catalog()[$operation] ?? throw new ApiError('GTM 操作无效');
        self::validateFields($params,$spec['fields']);
        $kind=$spec['kind'];$idField=self::KINDS[$kind][4];$id=$params[$idField] ?? '';
        if ($spec['create']) {
            if ($kind==='list' && (empty($params['InstanceId']) || empty($params['ScheduleZoneName']) || empty($params['ScheduleHostname']))) throw new ApiError('请选择已购实例并填写接入域名');
            if ($kind==='pools' && (empty($params['AddressPoolName']) || empty($params['AddressPoolType']))) throw new ApiError('请填写地址池名称和类型');
        }
        if ($kind==='addresses' && isset($params['Address'])) {
            $type=$params['Type'] ?? ($id ? $this->detail($account,$kind,$id)['resource']['Type'] ?? '' : '');
            $address=$params['Address'];
            if (($type==='IPv4' && !filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) || ($type==='IPv6' && !filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_IPV6)) || ($type==='domain' && !filter_var($address,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME))) throw new ApiError('服务地址与地址类型不匹配');
        }
        $before=[];$references=[];
        if (!$spec['create']) {
            $detail=$this->detail($account,$kind,$id);
            if (!$expected || !hash_equals($detail['token'],$expected)) throw new ApiError('云端配置已变化，请重新打开资源后编辑',409);
            $before=$detail['resource'];$references=$this->references($account,$kind,$id);
            if ($spec['delete'] && $references) throw new ApiError('资源仍被引用，请先在上级配置中解除关联',409);
        }
        if ($kind==='monitors' && isset($params['IspCityNodes']) && !$params['IspCityNodes']) throw new ApiError('请至少选择一个探测节点');
        $this->relationships($account,$spec,$params,$before);
        if (isset($params['ExtendInfo'])) {
            $extension=json_decode($params['ExtendInfo'],true,16,JSON_THROW_ON_ERROR);
            if (!is_array($extension)) throw new ApiError('探测协议参数无效');
            $protocol=$params['Protocol'] ?? $before['Protocol'] ?? '';
            if (in_array($protocol,['http','https'],true)) {
                if (isset($extension['path']) && (!is_string($extension['path']) || !str_starts_with($extension['path'],'/'))) throw new ApiError('HTTP 探测路径必须以 / 开头');
                if (isset($extension['code']) && !in_array($extension['code'],[400,500],true)) throw new ApiError('HTTP 异常码阈值无效');
                foreach (['sni','followRedirect'] as $key) if (isset($extension[$key]) && !is_bool($extension[$key])) throw new ApiError('HTTP 协议开关必须为布尔值');
            }
            if ($protocol==='ping') {
                if (isset($extension['packetNum']) && !in_array($extension['packetNum'],[20,50,100],true)) throw new ApiError('Ping 数据包数无效');
                if (isset($extension['packetLossRate']) && !in_array($extension['packetLossRate'],[10,30,40,80,90,100],true)) throw new ApiError('Ping 丢包阈值无效');
            }
        }
        $ticket=['account_id'=>$account['id'],'credentials_hash'=>hash('sha256',json_encode($account['credentials'])),'operation'=>$operation,'parameters'=>$params,'before'=>$before,'token'=>$expected,'expires'=>time()+600,'nonce'=>bin2hex(random_bytes(16))];
        return ['ticket'=>$this->security->encrypt(json_encode($ticket,JSON_THROW_ON_ERROR)),'operation'=>$operation,'label'=>$spec['label'],'before'=>$before,'parameters'=>$params,'references'=>$references,'expires'=>$ticket['expires']];
    }
    private function relationships(array $account,array $spec,array $params,array $before): void
    {
        foreach (['AddressPools'=>['AddressPoolId','pools'],'Addresses'=>['AddressId','addresses']] as $field=>[$idField,$kind]) {
            if (!isset($params[$field])) continue;
            $seen=[];$services=[];
            foreach ($params[$field] as $item) {
                $id=$item[$idField] ?? '';
                if ($id==='' || isset($seen[$id])) throw new ApiError('关联资源缺少 ID 或重复');
                $seen[$id]=true;$child=$this->detail($account,$kind,$id)['resource'];
                $type=$child[$kind==='pools'?'AddressPoolType':'Type'] ?? '';
                if ($field==='AddressPools') {
                    $allowed=match($before['ScheduleRrType'] ?? '') {'A'=>['IPv4','domain'],'AAAA'=>['IPv6','domain'],'CNAME'=>['domain'],default=>[]};
                    if (!in_array($type,$allowed,true)) throw new ApiError('地址池类型与接入记录类型不匹配');
                } elseif ($type!==($before['AddressPoolType'] ?? '')) throw new ApiError('地址类型与地址池不匹配');
                if ($field==='Addresses') {
                    $service=strtolower(rtrim($child['Address'] ?? '', '.'));
                    if (isset($services[$service])) throw new ApiError('同一地址池不能重复关联相同服务地址');
                    $services[$service]=true;
                }
            }
        }
        $ports=[];
        $addressType=$params['Type'] ?? $before['Type'] ?? null;
        foreach ($params['HealthTasks'] ?? [] as $task) {
            if (empty($task['TemplateId'])) throw new ApiError('请选择探测模板');
            $template=$this->detail($account,'monitors',$task['TemplateId'])['resource'];
            if (in_array($addressType,['IPv4','IPv6'],true) && ($template['IpVersion'] ?? null)!==$addressType) throw new ApiError('探测模板的 IP 版本与服务地址不匹配');
            if (($template['Protocol'] ?? '')==='ping') { if (isset($task['Port'])) throw new ApiError('Ping 探测不填写端口'); }
            elseif (!isset($task['Port'])) throw new ApiError('TCP/HTTP/HTTPS 探测必须填写端口');
            if (isset($task['Port'])) $ports[$task['Port']]=true;
        }
        if (count($ports)>5) throw new ApiError('单地址最多配置五个探测端口');
    }
    public function apply(array $account,string $encrypted): array
    {
        $ticket=json_decode($this->security->decrypt($encrypted),true,64,JSON_THROW_ON_ERROR);
        if (($ticket['account_id'] ?? null)!==$account['id'] || ($ticket['expires'] ?? 0)<time() || !hash_equals($ticket['credentials_hash'] ?? '',hash('sha256',json_encode($account['credentials'])))) throw new ApiError('变更预览已过期或账号已变化，请重新预览',409);
        $path=$this->store->directory().'/gtm-lock-'.$account['id'].'.php';$lock=fopen($path,'c+');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if (is_resource($lock)) fclose($lock);throw new ApiError('该账号有 GTM 操作进行中，请稍后重试',409); }
        if (fstat($lock)['size']===0) fwrite($lock,Store::GUARD);chmod($path,0600);
        try {
            foreach ($this->store->read()['gtm_changes'] ?? [] as $change) if ($change['nonce']===$ticket['nonce'] && $change['status']==='success') return $change['result'];
            $operation=$ticket['operation'];$spec=self::catalog()[$operation];$params=$ticket['parameters'];$kind=$spec['kind'];$idField=self::KINDS[$kind][4];$id=$params[$idField] ?? '';
            if (!$spec['create']) {
                $current=$this->detail($account,$kind,$id);
                if (!hash_equals($ticket['token'],$current['token'])) throw new ApiError('云端配置已变化，本次未提交。请重新编辑',409);
                if ($spec['delete'] && $this->references($account,$kind,$id)) throw new ApiError('资源已被引用，不能删除',409);
            }
            $this->relationships($account,$spec,$params,$ticket['before']);
            $change=['nonce'=>$ticket['nonce'],'account_id'=>$account['id'],'operation'=>$operation,'resource_id'=>$id,'parameters'=>$params,'before'=>$ticket['before'],'time'=>time(),'status'=>'pending'];
            $this->record($change);
            try {
                $response=$this->call($account,$operation,$params+(!empty($spec['clientToken'])?['ClientToken'=>$ticket['nonce']]:[]));
                if ($spec['create']) $id=$response[$idField] ?? '';
                $change['resource_id']=$id;
                $result=['submitted'=>true,'request_id'=>$response['RequestId'] ?? null,'resource_id'=>$id,'kind'=>$kind,'verification'=>'pending'];
                if (!$spec['delete'] && $id!=='') {
                    try { $result['resource']=$this->detail($account,$kind,$id)['resource'];$result['verification']=$this->matches($operation,$params,$result['resource'])?'confirmed':'pending'; }
                    catch (\Throwable) { $result['verification']='unavailable'; }
                } elseif ($spec['delete']) {
                    try { $remaining=$this->all($account,$kind);$result['verification']=array_filter($remaining,fn ($row)=>($row[$idField] ?? null)===$id)?'pending':'confirmed'; }
                    catch (\Throwable) { $result['verification']='unavailable'; }
                }
                $change['status']='success';$change['result']=$result;$this->record($change);
                return $result;
            } catch (\Throwable $error) {
                $change['status']='failed';$change['error']=$error instanceof \RuntimeException?$error->getMessage():'GTM 操作失败';$this->record($change);throw $error;
            }
        } finally { fclose($lock); }
    }
    private function matches(string $operation,array $params,array $resource): bool
    {
        $normalize=function(mixed $value,array $schema) use (&$normalize): mixed {
            if ($schema['type']==='array') {
                if (!is_array($value)) return null;
                if (!array_is_list($value)) $value=reset($value);
                if (!is_array($value)) return null;
                return array_map(fn ($child)=>$normalize($child,$schema['items']),$value);
            }
            if ($schema['type']==='object') {
                if (!is_array($value)) return null;
                $result=[];
                foreach ($schema['properties'] as $key=>$field) if (array_key_exists($key,$value)) $result[$key]=$normalize($value[$key],$field);
                return $result;
            }
            return $value;
        };
        $asSet=function(mixed $value) use (&$asSet): mixed {
            if (!is_array($value)) return $value;
            foreach ($value as &$child) $child=$asSet($child);
            unset($child);
            if (array_is_list($value)) usort($value,fn ($a,$b)=>strcmp(self::fingerprint(['v'=>$a]),self::fingerprint(['v'=>$b])));
            return $value;
        };
        $fields=self::catalog()[$operation]['fields'];
        foreach ($params as $key=>$value) {
            if (in_array($key,['InstanceId','ConfigId','AddressId','AddressPoolId','TemplateId','ClientToken'],true)) continue;
            if (!array_key_exists($key,$resource)) return false;
            if ($key==='ExtendInfo') {
                if (self::fingerprint(json_decode($value,true) ?? [])!==self::fingerprint(json_decode($resource[$key],true) ?? [])) return false;
            } elseif (self::fingerprint(['value'=>$asSet($normalize($resource[$key],$fields[$key]))])!==self::fingerprint(['value'=>$asSet($value)])) return false;
        }
        return true;
    }
    private function record(array $change): void
    {
        $this->store->transaction(function(array &$s) use ($change) {
            $s['gtm_changes'] ??=[];$found=false;
            foreach ($s['gtm_changes'] as &$row) if ($row['nonce']===$change['nonce']) {$row=$change;$found=true;break;}
            unset($row);if (!$found) $s['gtm_changes'][]=$change;
            $s['gtm_changes']=array_slice($s['gtm_changes'],-200);
            if ($change['status']!=='pending') Store::event($s,$change['operation'],'GTM / '.$change['account_id'].' / '.$change['resource_id'],$change['status']);
        });
    }
}

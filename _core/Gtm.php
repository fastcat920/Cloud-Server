<?php

declare(strict_types=1);
namespace CloudDesk;

final class GtmRequestError extends \RuntimeException
{
    public function __construct(string $message, public readonly array $details, \Throwable $previous) { parent::__construct($message,0,$previous); }
}
final class Gtm
{
    private const KINDS = [
        'list'=>['ListCloudGtmInstanceConfigs','InstanceConfigs','InstanceConfig','DescribeCloudGtmInstanceConfigFullInfo','ConfigId'],
        'pools'=>['ListCloudGtmAddressPools','AddressPools','AddressPool','DescribeCloudGtmAddressPool','AddressPoolId'],
        'addresses'=>['ListCloudGtmAddresses','Addresses','Address','DescribeCloudGtmAddress','AddressId'],
        'monitors'=>['ListCloudGtmMonitorTemplates','Templates','Template','DescribeCloudGtmMonitorTemplate','TemplateId'],
        'instances'=>['ListCloudGtmInstances','Instances','Instance'],
    ];
    private array $instanceIds = [];
    private ?array $previewCache = null;
    private float $cloudMs = 0;
    private int $cloudCalls = 0;
    private array $cloudTimings = [];
    public function metrics(): array { return ['cloud_ms'=>$this->cloudMs,'cloud_calls'=>$this->cloudCalls,'cloud_timings'=>$this->cloudTimings]; }
    private function previewReads(callable $callback): array
    {
        if ($this->previewCache!==null) return $callback();
        $this->previewCache=[];
        try { return $callback(); } finally { $this->previewCache=null; }
    }
    private function cacheKey(array $account,string $kind,string $id): string { return hash('sha256',json_encode([$account['id'],$account['credentials'],$kind,$id],JSON_THROW_ON_ERROR)); }
    public function __construct(private Cloud $cloud, private Store $store, private Security $security) {}
    public static function catalog(): array { static $catalog; return $catalog ??= json_decode(file_get_contents(__DIR__.'/gtm-operations.json'),true,64,JSON_THROW_ON_ERROR); }
    private function call(array $account,string $operation,array $params=[]): array
    {
        if (!empty($params['ConfigId']) && empty($params['InstanceId'])) {
            $params['InstanceId']=$this->instanceIds[$account['id']][$params['ConfigId']] ?? '';
            foreach ($params['InstanceId'] ? [] : $this->all($account,'list') as $config) {
                if (($config['ConfigId'] ?? null)===$params['ConfigId']) { $params['InstanceId']=$config['InstanceId'] ?? ''; break; }
            }
            if (empty($params['InstanceId'])) throw new ApiError('未找到接入配置对应的 GTM 实例，请重新查询列表');
        }
        foreach (self::catalog()[$operation]['json'] ?? [] as $field) {
            if (isset($params[$field]) && is_array($params[$field])) $params[$field]=json_encode($params[$field],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        }
        $read=!isset(self::catalog()[$operation]);
        for($attempt=0;$attempt<($read?2:1);$attempt++) {
            try {
                $started=hrtime(true);++$this->cloudCalls;
                Http::$lastTiming=[];
                try { return $this->cloud->rpc($account,$operation,['AcceptLanguage'=>'zh-CN']+$params,service:'alidns',method:$read?'GET':'POST',timeout:$read?15:60,reuse:true,queryPost:true); }
                finally {
                    $duration=(hrtime(true)-$started)/1000000;
                    $this->cloudMs+=$duration;
                    if (count($this->cloudTimings)<20) $this->cloudTimings[]=[$operation,$duration,$attempt+1,Http::$lastTiming];
                }
            } catch (\RuntimeException $error) {
                $transient=$error instanceof CloudResponseError ? in_array($error->httpStatus,[502,503,504],true) : preg_match('/[（(]28[）)]|HTTP 50[234]/',$error->getMessage())===1;
                if ($read && $attempt===0 && $transient) continue;
                $translated=self::requestError($operation,$error);
                $fields=self::catalog()[$operation]['fields'] ?? [];
                // Record only schema-approved business fields, never RPC authentication metadata.
                $submitted=array_intersect_key($params,$fields);
                if ($fields) $translated=new GtmRequestError($translated->getMessage(),$translated->details+[
                    'request_method'=>'POST',
                    'parameter_location'=>'URL 查询参数（与官方 SDK 一致）',
                    'request_parameters'=>json_encode($submitted,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),
                ],$translated);
                throw $translated;
            }
        }
        throw new \RuntimeException('GTM 读取失败');
    }
    public static function requestError(string $operation, \RuntimeException $error): GtmRequestError
    {
        $code=$error instanceof CloudResponseError?$error->providerCode:'';
        $request=$error instanceof CloudResponseError?$error->requestId:'';
        $status=$error instanceof CloudResponseError?$error->httpStatus:0;
        $raw=$error->getMessage();
        $displayRaw=$code!=='' && str_starts_with($raw,$code.'：')?substr($raw,strlen($code.'：')):$raw;
        $reason=match(true) {
            str_contains($code,'HealthJudgement')=>'健康判定条件缺失或不正确，请重新选择健康判定条件。',
            str_contains($code,'DispatchTypeNotMatchMonitorTemplate')=>'地址类型与探测模板不匹配，请选择相同 IP 版本的模板。',
            str_contains($code,'InnerIpCanNotHasMonitorTask')=>'内网地址不支持配置健康探测任务。',
            str_contains($code,'MonitorTaskQuota')=>'健康探测任务数量超过当前套餐限制。',
            str_contains($code,'ReferenceStandardInstanceUseUltimateQuota')=>'探测模板配置超出关联实例的套餐能力，请检查探测间隔和探测节点。',
            str_contains($code,'NameConflict') || str_contains($code,'NameDuplicate')=>'资源名称已被使用，请更换名称。',
            str_contains($code,'ValueFormat') || str_contains($code,'ValueIpv4') || str_contains($code,'ValueIpv6') || str_contains($code,'ValueDomain')=>'服务地址格式不正确，请检查 IP 地址或域名。',
            str_contains($code,'Port')=>'探测端口不正确；Ping 不填写端口，TCP、HTTP、HTTPS 需要有效端口。',
            preg_match('/(Forbidden|Unauthorized|AccessDenied|InvalidAccessKey|SignatureDoesNotMatch)/i',$code)===1 || $status===403 || $status===401=>'云账号认证或授权失败，请检查凭证与 RAM 权限。',
            preg_match('/(NotExist|NotExsit|NotFound)/i',$code)===1=>'资源不存在或已被删除，请刷新后重新选择。',
            str_contains($code,'Quota') || str_contains($code,'ExceedMax')=>'配置超过当前账号或套餐的资源限制。',
            preg_match('/[\x{4e00}-\x{9fff}]/u',$displayRaw)===1=>$displayRaw,
            $status>=500=>'阿里云服务暂时异常，请稍后重试；若已提交变更，请先刷新核对结果。',
            $status===400 || preg_match('/params? error|invalid.*param|missing.*param/i',$raw)===1=>'阿里云拒绝了请求参数，请核对配置。可展开技术详情查看错误码和请求编号。',
            default=>'阿里云请求失败，请查看技术详情；若正在保存，请先刷新核对云端结果。',
        };
        $spec=self::catalog()[$operation] ?? null;
        $kind=['list'=>'接入域名','pools'=>'地址池','addresses'=>'地址','monitors'=>'探测模板'][$spec['kind'] ?? ''] ?? 'GTM 资源';
        $label=$spec ? $kind.'「'.$spec['label'].'」操作失败' : '读取 GTM 资源失败';
        $original=preg_match('/^.{0,4000}/us',$raw,$limited)===1?$limited[0]:'原始错误无法解码';
        return new GtmRequestError($label.'：'.$reason,['operation'=>$operation,'code'=>$code,'request_id'=>$request,'http_status'=>$status ?: null,'original_message'=>$original],$error);
    }
    public function listing(array $account,string $kind,int $page=1,array $filters=[]): array
    {
        $map=self::KINDS[$kind] ?? throw new ApiError('GTM 资源类型无效');
        $data=$this->call($account,$map[0],['PageNumber'=>max(1,$page),'PageSize'=>100]+$filters);
        $items=$data[$map[1]][$map[2]] ?? [];
        if (!is_array($items) || ($items && !array_is_list($items))) throw new ApiError('GTM 列表返回格式异常');
        if ($kind==='list') foreach ($items as $item) if (isset($item['ConfigId'],$item['InstanceId'])) $this->instanceIds[$account['id']][$item['ConfigId']]=$item['InstanceId'];
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
    public function detail(array $account,string $kind,string $id,string $instanceId=''): array
    {
        $map=self::KINDS[$kind] ?? throw new ApiError('GTM 资源类型无效');
        if (!isset($map[3]) || $id==='') throw new ApiError('缺少 GTM 资源 ID');
        $cacheKey='detail:'.$this->cacheKey($account,$kind,$id);
        if (isset($this->previewCache[$cacheKey])) return $this->previewCache[$cacheKey];
        $resource=$this->call($account,$map[3],[$map[4]=>$id]+($kind==='list' && $instanceId!==''?['InstanceId'=>$instanceId]:[]));
        if (($resource[$map[4]] ?? null)!==$id) throw new ApiError('GTM 详情返回的资源标识不匹配');
        $detail=['resource'=>$resource,'token'=>self::fingerprint($resource),'kind'=>$kind];
        if ($this->previewCache!==null) $this->previewCache[$cacheKey]=$detail;
        return $detail;
    }
    public function references(array $account,string $kind,string $id): array
    {
        $key='references:'.$this->cacheKey($account,$kind,$id);
        if (isset($this->previewCache[$key])) return $this->previewCache[$key];
        $result=$this->readReferences($account,$kind,$id);
        if ($this->previewCache!==null) $this->previewCache[$key]=$result;
        return $result;
    }
    private function readReferences(array $account,string $kind,string $id): array
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
    public function options(array $account, ?string $operation = null, bool $unified = false, ?string $section = null): array
    {
        $result=['errors'=>[]];
        $needed=array_fill_keys([...array_keys(self::KINDS),'nodes','lines'],true);
        if ($operation !== null) {
            $spec=self::catalog()[$operation] ?? throw new ApiError('GTM 操作无效');
            if ($unified && !$spec['create'] && !$spec['delete']) {
                foreach (self::catalog() as $candidate) if ($candidate['kind']===$spec['kind'] && !$candidate['create'] && !$candidate['delete']) $spec['fields']=array_replace($spec['fields'],$candidate['fields']);
            }
            $needed=[];
            $walk=function(array $fields,bool $nested=false) use (&$walk,&$needed,$spec): void {
                foreach ($fields as $name=>$field) {
                    if (($nested || $spec['create']) && isset(['AddressPoolId'=>'pools','AddressId'=>'addresses','TemplateId'=>'monitors','InstanceId'=>'instances'][$name])) $needed[['AddressPoolId'=>'pools','AddressId'=>'addresses','TemplateId'=>'monitors','InstanceId'=>'instances'][$name]]=true;
                    if ($name==='RequestSource') $needed['lines']=true;
                    if ($name==='IspCityNodes') $needed['nodes']=true;
                    if (isset($field['items']['properties'])) $walk($field['items']['properties'],true);
                }
            };
            $walk($spec['fields']);
        }
        if ($section!==null) {
            if (!in_array($section,[...array_keys(self::KINDS),'nodes','lines'],true)) throw new ApiError('GTM 选项类型无效');
            $needed=array_intersect_key($needed,[$section=>true]);
        }
        foreach (array_keys(self::KINDS) as $kind) {
            if (!isset($needed[$kind])) continue;
            try { $result[$kind]=$this->all($account,$kind); }
            catch (\RuntimeException $error) { $result[$kind]=[];$result['errors'][$kind]=$error->getMessage(); }
        }
        foreach (['nodes'=>'ListCloudGtmMonitorNodes','lines'=>'DescribeCloudGtmSystemLines'] as $key=>$operation) {
            if (!isset($needed[$key])) continue;
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
        return $this->previewReads(fn ()=>$this->previewOne($account,$operation,$params,$expected));
    }
    public function previewListDelete(array $account,string $operation,array $params): array
    {
        $spec=self::catalog()[$operation] ?? null;
        if (empty($spec['delete'])) throw new ApiError('此操作仅支持删除 GTM 资源');
        self::validateFields($params,$spec['fields']);
        $kind=$spec['kind'];
        $id=$params[self::KINDS[$kind][4]];
        return $this->previewReads(function () use ($account,$operation,$params,$kind,$id) {
            $detail=$this->detail($account,$kind,$id,$params['InstanceId'] ?? '');
            return $this->previewOne($account,$operation,$params,$detail['token']);
        });
    }
    private function previewOne(array $account,string $operation,array $params,?string $expected): array
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
            $detail=$this->detail($account,$kind,$id,$params['InstanceId'] ?? '');
            if (!$expected || !hash_equals($detail['token'],$expected)) throw new ApiError('云端配置已变化，请重新打开资源后编辑',409);
            $before=$detail['resource'];$references=$this->references($account,$kind,$id);
            if ($spec['delete'] && $references) throw new ApiError('资源仍被引用，请先在上级配置中解除关联',409);
        }
        // Match the console's complete identity fields even when only health tasks changed.
        // Freeze them in the preview, never fill them from a later cloud read at apply time.
        if ($operation==='UpdateCloudGtmAddress') {
            foreach (['Name','Address'] as $field) {
                if (!array_key_exists($field,$params)) $params[$field]=$before[$field] ?? null;
                if (!is_string($params[$field]) || $params[$field]==='') throw new ApiError('云端地址名称或服务地址缺失，请刷新后重新编辑');
            }
            if (!array_key_exists('HealthTasks',$params) && array_key_exists('HealthTasks',$before)) {
                $params['HealthTasks']=self::savedHealthTasks($before['HealthTasks']);
            }
            if (!array_key_exists('HealthJudgement',$params) && isset($before['HealthJudgement'])) $params['HealthJudgement']=$before['HealthJudgement'];
            self::validateFields($params,$spec['fields']);
        }
        if ($kind==='addresses' && !empty($params['HealthTasks']) && !isset($params['HealthJudgement'])) {
            $params['HealthJudgement']=$before['HealthJudgement'] ?? null;
            if (!in_array($params['HealthJudgement'],$spec['fields']['HealthJudgement']['enum'],true)) throw new ApiError('请明确选择健康判定条件');
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
    public function previewBatch(array $account,array $steps,?string $expected): array
    {
        return $this->previewReads(fn ()=>$this->previewBatchOne($account,$steps,$expected));
    }
    private function previewBatchOne(array $account,array $steps,?string $expected): array
    {
        if (!array_is_list($steps) || !$steps || count($steps)>8) throw new ApiError('变更步骤无效');
        $previews=[];$target=null;
        foreach ($steps as $step) {
            $operation=$step['operation'] ?? '';$params=$step['parameters'] ?? null;
            $spec=self::catalog()[$operation] ?? throw new ApiError('GTM 操作无效');
            if ($spec['create'] || $spec['delete'] || !is_array($params)) throw new ApiError('合并编辑只允许修改同一资源');
            $identity=[$spec['kind'],$params[self::KINDS[$spec['kind']][4]] ?? ''];
            if ($target!==null && $target!==$identity) throw new ApiError('合并编辑必须为同一资源');
            $target=$identity;
            if (isset($previews[$operation])) throw new ApiError('重复的变更步骤');
            $previews[$operation]=$this->preview($account,$operation,$params,$expected);
        }
        $first=reset($previews);$parameters=[];
        foreach ($previews as $preview) $parameters=array_replace($parameters,$preview['parameters']);
        $ticket=['account_id'=>$account['id'],'credentials_hash'=>hash('sha256',json_encode($account['credentials'])),'expires'=>time()+600,'batch'=>array_column(array_values($previews),'ticket')];
        return ['ticket'=>$this->security->encrypt(json_encode($ticket,JSON_THROW_ON_ERROR)),'label'=>'保存配置','before'=>$first['before'],'parameters'=>$parameters,'references'=>$first['references'],'expires'=>$ticket['expires']];
    }
    private function applyBatch(array $account,array $tickets,mixed $lock): array
    {
        $results=[];$previous=null;
        foreach ($tickets as $encrypted) {
            $child=json_decode($this->security->decrypt($encrypted),true,64,JSON_THROW_ON_ERROR);
            if ($previous!==null) {
                $child['before']=$previous['resource'];$child['token']=self::fingerprint($previous['resource']);
                $encrypted=$this->security->encrypt(json_encode($child,JSON_THROW_ON_ERROR));
            }
            try { $result=$this->apply($account,$encrypted,$lock); }
            catch (\Throwable $error) {
                if (!$results) throw $error;
                return $previous+['steps'=>$results,'partial'=>true,'error'=>$error->getMessage()]+($error instanceof GtmRequestError?['error_details'=>$error->details]:[]);
            }
            $results[]=$result;$previous=$result;
            if ($result['verification']!=='confirmed') return $result+['steps'=>$results,'partial'=>count($results)<count($tickets)];
        }
        return $previous+['steps'=>$results];
    }
    private static function savedHealthTasks(mixed $value): array
    {
        if (!is_array($value)) throw new ApiError('云端探测任务格式异常，请刷新后重试');
        $tasks=array_is_list($value)?$value:($value['HealthTask'] ?? null);
        if (!is_array($tasks) || !array_is_list($tasks)) throw new ApiError('云端探测任务格式异常，请刷新后重试');
        return array_map(static function($task) {
            if (!is_array($task) || !is_string($task['TemplateId'] ?? null)) throw new ApiError('云端探测模板标识缺失，请刷新后重试');
            $saved=['TemplateId'=>$task['TemplateId']];
            if (isset($task['Port']) && $task['Port']!==0 && $task['Port']!=='0') {
                $port=filter_var($task['Port'],FILTER_VALIDATE_INT);
                if ($port===false) throw new ApiError('云端探测端口格式异常，请刷新后重试');
                $saved['Port']=$port;
            }
            return $saved;
        },$tasks);
    }
    private function relationships(array $account,array $spec,array $params,array $before): void
    {
        foreach (['AddressPools'=>['AddressPoolId','pools'],'Addresses'=>['AddressId','addresses']] as $field=>[$idField,$kind]) {
            if (!isset($params[$field])) continue;
            $seen=[];$services=[];
            foreach ($params[$field] as $item) {
                $id=$item[$idField] ?? '';
                if ($id==='' || isset($seen[$id])) throw new ApiError('关联资源缺少 ID 或重复');
                $seen[$id]=true;$child=null;
                $container=$before[$field] ?? [];
                $members=$container[$field==='AddressPools'?'AddressPool':'Address'] ?? [];
                foreach ($members as $member) if (($member[$idField] ?? null)===$id) {$child=$member;break;}
                $child ??= $this->detail($account,$kind,$id)['resource'];
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
        $ports=[];$templates=[];
        $addressType=$params['Type'] ?? $before['Type'] ?? null;
        $unchangedTasks=false;
        if (array_key_exists('HealthTasks',$before)) {
            try {$unchangedTasks=($params['HealthTasks'] ?? null)==self::savedHealthTasks($before['HealthTasks']);} catch (ApiError) { /* Explicit replacement can repair malformed old tasks. */ }
        }
        foreach ($params['HealthTasks'] ?? [] as $task) {
            if ($unchangedTasks) { if(isset($task['Port']))$ports[$task['Port']]=true;continue; }
            if (empty($task['TemplateId'])) throw new ApiError('请选择探测模板');
            $template=$templates[$task['TemplateId']] ??= $this->detail($account,'monitors',$task['TemplateId'])['resource'];
            if (in_array($addressType,['IPv4','IPv6'],true) && ($template['IpVersion'] ?? null)!==$addressType) throw new ApiError('探测模板的 IP 版本与服务地址不匹配');
            if (($template['Protocol'] ?? '')==='ping') { if (isset($task['Port'])) throw new ApiError('Ping 探测不填写端口'); }
            elseif (!isset($task['Port'])) throw new ApiError('TCP/HTTP/HTTPS 探测必须填写端口');
            if (isset($task['Port'])) $ports[$task['Port']]=true;
        }
        if (count($ports)>5) throw new ApiError('单地址最多配置五个探测端口');
    }
    public function apply(array $account,string $encrypted,mixed $heldLock=null): array
    {
        $ticket=json_decode($this->security->decrypt($encrypted),true,64,JSON_THROW_ON_ERROR);
        if (($ticket['account_id'] ?? null)!==$account['id'] || ($ticket['expires'] ?? 0)<time() || !hash_equals($ticket['credentials_hash'] ?? '',hash('sha256',json_encode($account['credentials'])))) throw new ApiError('变更预览已过期或账号已变化，请重新预览',409);
        $lock=$heldLock;
        if ($lock===null) {
            $path=$this->store->directory().'/gtm-lock-'.$account['id'].'.php';$lock=fopen($path,'c+');
            if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if (is_resource($lock)) fclose($lock);throw new ApiError('该账号有 GTM 操作进行中，请稍后重试',409); }
            if (fstat($lock)['size']===0) fwrite($lock,Store::GUARD);chmod($path,0600);
        }
        try {
            if (isset($ticket['batch'])) return $this->applyBatch($account,$ticket['batch'],$lock);
            foreach ($this->store->read()['gtm_changes'] ?? [] as $change) if ($change['nonce']===$ticket['nonce'] && $change['status']==='success') return $change['result'];
            $operation=$ticket['operation'];$spec=self::catalog()[$operation];$params=$ticket['parameters'];$kind=$spec['kind'];$idField=self::KINDS[$kind][4];$id=$params[$idField] ?? '';
            if ($operation==='UpdateCloudGtmAddress' && (empty($params['Name']) || empty($params['Address']))) throw new ApiError('此预览缺少完整地址信息，请返回编辑重新预览',409);
            if ($operation==='UpdateCloudGtmAddress' && array_key_exists('HealthTasks',$ticket['before']) && !array_key_exists('HealthTasks',$params)) throw new ApiError('此提交缺少原有探测配置，请重新打开地址后提交',409);
            if ($kind==='addresses' && !empty($params['HealthTasks']) && !isset($params['HealthJudgement'])) throw new ApiError('此预览缺少健康判定条件，请返回编辑重新预览',409);
            if (!$spec['create']) {
                $current=$this->detail($account,$kind,$id,$params['InstanceId'] ?? $ticket['before']['InstanceId'] ?? '');
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
                    try { $result['resource']=$this->detail($account,$kind,$id,$params['InstanceId'] ?? $ticket['before']['InstanceId'] ?? '')['resource'];$result['token']=self::fingerprint($result['resource']);$result['verification']=$this->matches($operation,$params,$result['resource'])?'confirmed':'pending'; }
                    catch (\Throwable) { $result['verification']='unavailable'; }
                } elseif ($spec['delete']) {
                    try {
                        $first=$this->listing($account,$kind);
                        $pageData=$first;$seen=count($first['items']);$page=1;
                        while (!array_filter($pageData['items'],fn ($row)=>($row[$idField] ?? null)===$id)) {
                            if ($seen >= $first['total']) { $result['verification']='confirmed';break; }
                            if (!$pageData['items'] || $page>=50) break;
                            $pageData=$this->listing($account,$kind,++$page);$seen+=count($pageData['items']);
                        }
                        $verifiedPage=$first;
                    }
                    catch (\Throwable) { $result['verification']='unavailable'; }
                }
                $change['status']='success';$change['result']=$result;$this->record($change);
                if (isset($verifiedPage)) $result['verified_page']=$verifiedPage;
                return $result;
            } catch (\Throwable $error) {
                $change['status']='failed';$change['error']=$error instanceof \RuntimeException?$error->getMessage():'GTM 操作失败';if ($error instanceof GtmRequestError) {
                    if ($kind==='addresses') {
                        $context=array_intersect_key($ticket['before'],array_flip(['Type','Address','AvailableMode','HealthJudgement','HealthTasks']));
                        $error=new GtmRequestError($error->getMessage(),$error->details+['address_context'=>json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)],$error);
                    }
                    $change['error_details']=$error->details;
                }$this->record($change);throw $error;
            }
        } finally { if ($heldLock===null) fclose($lock); }
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

<?php
// Stateful, isolated GTM cloud double. Loaded only by tests/router.php or tests/gtm.php.
function gtmFixture(array $query): array
{
    $path=getenv('CLOUD_DESK_DATA').'/gtm-fixture.php';
    $guard=CloudDesk\Store::GUARD;
    $s=is_file($path)?json_decode(substr(file_get_contents($path),strlen($guard)),true):[
        'list'=>['config-1'=>['ConfigId'=>'config-1','InstanceId'=>'gtm-1','ScheduleDomainName'=>'api.unit.test','ScheduleZoneName'=>'unit.test','ScheduleHostname'=>'api','ScheduleRrType'=>'A','Ttl'=>60,'EnableStatus'=>'enable','HealthStatus'=>'ok','AvailableStatus'=>'available','AddressPoolLbStrategy'=>'sequence','SequenceLbStrategyMode'=>'preemptive','AddressPools'=>[['AddressPoolId'=>'pool-1','WeightValue'=>1,'SerialNumber'=>1,'RequestSource'=>['default']]],'Remark'=>'production']],
        'pools'=>['pool-1'=>['AddressPoolId'=>'pool-1','AddressPoolName'=>'Primary pool','AddressPoolType'=>'IPv4','HealthJudgement'=>'any_ok','EnableStatus'=>'enable','HealthStatus'=>'ok','AvailableStatus'=>'available','AddressLbStrategy'=>'weight','Addresses'=>[['AddressId'=>'addr-1','WeightValue'=>10,'SerialNumber'=>1,'RequestSource'=>['default']]],'Remark'=>'primary']],
        'addresses'=>['addr-1'=>['AddressId'=>'addr-1','Name'=>'Primary endpoint','Type'=>'IPv4','Address'=>'192.0.2.10','EnableStatus'=>'enable','AvailableMode'=>'auto','HealthJudgement'=>'any_ok','HealthStatus'=>'ok','AvailableStatus'=>'available','HealthTasks'=>[['TemplateId'=>'mtp-1','Port'=>443]],'Remark'=>'production']],
        'monitors'=>['mtp-1'=>['TemplateId'=>'mtp-1','Name'=>'HTTPS monitor','IpVersion'=>'IPv4','Protocol'=>'https','Interval'=>60,'Timeout'=>5000,'EvaluationCount'=>3,'FailureRate'=>50,'ExtendInfo'=>'{"host":"api.unit.test","path":"/health","code":500,"sni":true,"followRedirect":false}','IspCityNodes'=>[['CityCode'=>'503','IspCode'=>'465','CityName'=>'Test city','IspName'=>'Test ISP']],'Remark'=>'existing']],
        'sequence'=>1,'calls'=>[],
    ];
    $action=$query['Action'];$s['calls'][]=$query;
    if (isset($query['ConfigId']) && (!isset($query['InstanceId']) || ($s['list'][$query['ConfigId']]['InstanceId'] ?? null)!==$query['InstanceId'])) throw new RuntimeException('params error: missing or mismatched InstanceId');
    $expand=function(string $kind,array $row) use (&$expand,&$s): array {
        if($kind==='list')$row['AddressPools']=['AddressPool'=>array_map(fn($item)=>$expand('pools',array_merge($s['pools'][$item['AddressPoolId']],$item)),$row['AddressPools']??[])];
        if($kind==='pools')$row['Addresses']=['Address'=>array_map(fn($item)=>$expand('addresses',array_merge($s['addresses'][$item['AddressId']],$item)),$row['Addresses']??[])];
        if($kind==='addresses')$row['HealthTasks']=['HealthTask'=>array_map(fn($item)=>$item+['TemplateName'=>$s['monitors'][$item['TemplateId']]['Name'],'MonitorStatus'=>'ok'],$row['HealthTasks']??[])];
        if($kind==='monitors')$row['IspCityNodes']=['IspCityNode'=>$row['IspCityNodes']??[]];
        return $row;
    };
    $maps=['list'=>['InstanceConfigs','InstanceConfig','ConfigId','CloudGtmInstanceConfig'],'pools'=>['AddressPools','AddressPool','AddressPoolId','CloudGtmAddressPool'],'addresses'=>['Addresses','Address','AddressId','CloudGtmAddress'],'monitors'=>['Templates','Template','TemplateId','CloudGtmMonitorTemplate']];
    $result=['RequestId'=>'gtm-test-request'];
    if($action==='DescribeCloudGtmSystemLines')$result['SystemLines']=['SystemLine'=>[['Code'=>'default','DisplayName'=>'Global default','IsAvailable'=>true],['Code'=>'cn','DisplayName'=>'Mainland China','IsAvailable'=>true]]];
    elseif($action==='ListCloudGtmMonitorNodes') {
        foreach(['Ipv4','Ipv6']as$ip)$result[$ip.'IspCityNodes']=[$ip.'IspCityNode'=>[['CityCode'=>'503','IspCode'=>'465','CityName'=>'Test city','IspName'=>'Test ISP','GroupName'=>'BGP']]];
    } elseif($action==='ListCloudGtmInstances')$result+=['TotalItems'=>1,'Instances'=>['Instance'=>[['InstanceId'=>'gtm-1','InstanceName'=>'Purchased GTM','ChargeType'=>'prepay']]]];
    elseif($action==='DescribeCloudGtmAddressPoolReference') {
        $result['InstanceConfigs']=['InstanceConfig'=>array_values(array_filter(array_map(fn($row)=>$expand('list',$row),$s['list']),fn($row)=>in_array($query['AddressPoolId'],array_column($row['AddressPools']['AddressPool'],'AddressPoolId'),true)))];
    } elseif($action==='DescribeCloudGtmAddressReference') {
        $result['AddressPools']=['AddressPool'=>array_values(array_filter(array_map(fn($row)=>$expand('pools',$row),$s['pools']),fn($row)=>in_array($query['AddressId'],array_column($row['Addresses']['Address'],'AddressId'),true)))];
    } else {
        $kind=null;
        foreach($maps as$k=>$map)if(str_contains($action,$map[3]) || $action==='List'.$map[3].'s'){$kind=$k;break;}
        if(!$kind)throw new RuntimeException('Unknown GTM fixture action '.$action);
        [$plural,$singular,$idField]=$maps[$kind];
        $params=array_diff_key($query,array_flip(['Action','AcceptLanguage','ClientToken','Format','Version','AccessKeyId','SignatureMethod','SignatureVersion','SignatureNonce','Timestamp','Signature','PageNumber','PageSize']));
        foreach(['HealthTasks','AddressPools','Addresses','IspCityNodes']as$key)if(isset($params[$key])&&is_string($params[$key]))$params[$key]=json_decode($params[$key],true,512,JSON_THROW_ON_ERROR);
        foreach (CloudDesk\Gtm::catalog()[$action]['fields'] ?? [] as $key=>$field) if (isset($params[$key]) && $field['type']==='integer') $params[$key]=(int)$params[$key];
        if(str_starts_with($action,'List')) {
            $items=array_values($s[$kind]);
            if(isset($query['MonitorTemplateId']))$items=array_values(array_filter($items,fn($row)=>in_array($query['MonitorTemplateId'],array_column($row['HealthTasks']??[],'TemplateId'),true)));
            $total=count($items);$items=array_slice($items,((int)($query['PageNumber']??1)-1)*100,100);
            $result+=['TotalItems'=>$total,$plural=>[$singular=>array_map(fn($row)=>$expand($kind,$row),$items)]];
        } elseif(str_starts_with($action,'Describe')) {
            $id=$query[$idField]??'';if(!isset($s[$kind][$id]))throw new RuntimeException('Resource not found');$result+=$expand($kind,$s[$kind][$id]);
        } elseif(str_starts_with($action,'Create')) {
            $id=match($kind){'list'=>'config-','pools'=>'pool-','addresses'=>'addr-',default=>'mtp-'}.(++$s['sequence']);
            $row=$params+[$idField=>$id];
            if($kind==='list')$row['ScheduleDomainName']=$params['ScheduleHostname'].'.'.$params['ScheduleZoneName'];
            $s[$kind][$id]=$row;$result+=[$idField=>$id,'Success'=>true];
        } else {
            $id=$params[$idField]??'';if(!isset($s[$kind][$id]))throw new RuntimeException('Resource not found');
            if(str_starts_with($action,'Delete'))unset($s[$kind][$id]);
            else $s[$kind][$id]=array_merge($s[$kind][$id],$params);
            $result+=[$idField=>$id,'Success'=>true];
        }
    }
    file_put_contents($path,$guard.json_encode($s,JSON_THROW_ON_ERROR));chmod($path,0600);
    return ['status'=>200,'body'=>json_encode($result,JSON_THROW_ON_ERROR)];
}

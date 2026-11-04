<?php
namespace App\Services;
use App\Models\CloudAccount;
class CloudComputeService
{
    private $api;
    public function __construct(CloudBillingService $api) { $this->api=$api; }
    public function regions(CloudAccount $account) {
        $action=['aliyun'=>'DescribeRegions','tencent'=>'DescribeRegions','ucloud'=>'GetRegion'][$account->provider];
        $data=$this->api->resourceRequest($account,$action);
        $items=$account->provider==='aliyun'?($data['Regions']['Region']??[]):($account->provider==='tencent'?($data['RegionSet']??[]):($data['Regions']??[]));
        $rows=[];
        foreach ($items as $item) { $id=$item['RegionId']??$item['Region']??null; if ($id) $rows[$id]=['id'=>$id,'name'=>$item['LocalName']??$item['RegionName']??$id]; }
        return array_values($rows);
    }
    public function instances(CloudAccount $account,$region,$page,$project=null) {
        $offset=($page-1)*20;
        if ($account->provider==='aliyun') {
            $data=$this->api->resourceRequest($account,'DescribeInstances',['RegionId'=>$region,'PageNumber'=>$page,'PageSize'=>20],$region);
            $items=$data['Instances']['Instance']??[];$total=$data['TotalCount']??0;
            $rows=array_map(fn($r)=>['id'=>$r['InstanceId'],'name'=>$r['InstanceName']??$r['InstanceId'],'status'=>$r['Status']??'','ip'=>implode(', ',$r['PublicIpAddress']['IpAddress']??[]),'private_ip'=>implode(', ',$r['VpcAttributes']['PrivateIpAddress']['IpAddress']??[]),'cpu'=>$r['Cpu']??null,'memory_gb'=>isset($r['Memory'])?$r['Memory']/1024:null,'zone'=>$r['ZoneId']??'','expires_at'=>$r['ExpiredTime']??''],$items);
        } elseif ($account->provider==='tencent') {
            $data=$this->api->resourceRequest($account,'DescribeInstances',['Offset'=>$offset,'Limit'=>20],$region);
            $items=$data['InstanceSet']??[];$total=$data['TotalCount']??0;
            $rows=array_map(fn($r)=>['id'=>$r['InstanceId'],'name'=>$r['InstanceName']??$r['InstanceId'],'status'=>$r['InstanceState']??'','ip'=>implode(', ',$r['PublicIpAddresses']??[]),'private_ip'=>implode(', ',$r['PrivateIpAddresses']??[]),'cpu'=>$r['CPU']??null,'memory_gb'=>$r['Memory']??null,'zone'=>$r['Placement']['Zone']??'','expires_at'=>$r['ExpiredTime']??''],$items);
        } else {
            $params=['Region'=>$region,'Offset'=>$offset,'Limit'=>20];if ($project) $params['ProjectId']=$project;
            $data=$this->api->resourceRequest($account,'DescribeUHostInstance',$params,$region);
            $items=$data['UHostSet']??[];$total=$data['TotalCount']??0;
            $rows=array_map(function($r) { $public=[];$private=[];foreach ($r['IPSet']??[] as $ip) { if (($ip['Type']??'')==='Private') $private[]=$ip['IP']; else $public[]=$ip['IP']; }return ['id'=>$r['UHostId'],'name'=>$r['Name']??$r['UHostId'],'status'=>$r['State']??'','ip'=>implode(', ',$public),'private_ip'=>implode(', ',$private),'cpu'=>$r['CPU']??null,'memory_gb'=>isset($r['Memory'])?$r['Memory']/1024:null,'zone'=>$r['Zone']??'','expires_at'=>isset($r['ExpireTime'])?date('c',$r['ExpireTime']):'']; },$items);
        }
        return ['items'=>$rows,'total'=>(int)$total];
    }
    public function operate(CloudAccount $account,$region,$id,$operation,$project=null) {
        $actions=['aliyun'=>['start'=>'StartInstance','stop'=>'StopInstance','reboot'=>'RebootInstance'],'tencent'=>['start'=>'StartInstances','stop'=>'StopInstances','reboot'=>'RebootInstances'],'ucloud'=>['start'=>'StartUHostInstance','stop'=>'StopUHostInstance','reboot'=>'RebootUHostInstance']];
        if (!isset($actions[$account->provider][$operation])) abort(422,'不支持的操作');
        if ($account->provider==='aliyun') $params=['RegionId'=>$region,'InstanceId'=>$id];
        elseif ($account->provider==='tencent') $params=['InstanceIds'=>[$id]];
        else { $params=['Region'=>$region,'UHostId'=>$id];if ($project) $params['ProjectId']=$project; }
        return $this->api->resourceRequest($account,$actions[$account->provider][$operation],$params,$region);
    }
}

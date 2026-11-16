<?php

declare(strict_types=1);
foreach (['Store','Security','Cloud','Objects','Configs','Plans','Checks','Releases','Gtm','Importer','Api'] as $name) require __DIR__.'/../_core/'.$name.'.php';
require __DIR__.'/gtm-fixture.php';
use CloudDesk\{Store,Security,Cloud,Http,Gtm,ApiError};
function verifyGtm(bool $ok,string $why): void { if(!$ok)throw new RuntimeException($why); }
$directory=sys_get_temp_dir().'/gtm-test-'.bin2hex(random_bytes(8));putenv('CLOUD_DESK_DATA='.$directory);
$store=new Store($directory);$security=new Security($store);$cloud=new Cloud($security);$gtm=new Gtm($cloud,$store,$security);
$account=['id'=>1,'provider'=>'aliyun','enabled'=>true,'credentials'=>['key'=>$security->encrypt('fake'),'secret'=>$security->encrypt('fake')]];
Http::$transport=function($method,$url,$headers,$body){parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);
 if($method==='POST') {
  verifyGtm($body==='' && isset($query['Action'],$query['Signature']),'GTM must match official SDK query-only POST transport');
  verifyGtm(($headers['Content-Type']??'')==='application/x-www-form-urlencoded','GTM mutation form content type');
  $signature=$query['Signature'];$unsigned=$query;unset($unsigned['Signature']);ksort($unsigned);
  $expected=base64_encode(hash_hmac('sha1','POST&%2F&'.rawurlencode(http_build_query($unsigned,'','&',PHP_QUERY_RFC3986)),'fake&',true));
  verifyGtm(hash_equals($expected,$signature),'Signed RPC method must match POST');
 }
 return gtmFixture($query);};
try {
 $list=$gtm->listing($account,'list');verifyGtm(count($list['items'])===1 && $list['items'][0]['ConfigId']==='config-1','Typed list extraction');
 $detail=$gtm->detail($account,'list','config-1');verifyGtm($detail['resource']['AddressPools']['AddressPool'][0]['Addresses']['Address'][0]['AddressId']==='addr-1','Complete topology');
 $fixture=json_decode(substr(file_get_contents($directory.'/gtm-fixture.php'),strlen(Store::GUARD)),true);
 $details=array_values(array_filter($fixture['calls'],fn($row)=>$row['Action']==='DescribeCloudGtmInstanceConfigFullInfo'));
 verifyGtm($details[0]['InstanceId']==='gtm-1' && $details[0]['ConfigId']==='config-1','Detail must send both instance and config identifiers');
 $options=$gtm->options($account);verifyGtm(count($options['instances'])===1 && $options['nodes'] && !$options['errors'],'Resource selectors');
 $preview=$gtm->preview($account,'UpdateCloudGtmInstanceConfigBasic',['ConfigId'=>'config-1','InstanceId'=>'gtm-1','Ttl'=>90],$detail['token']);
 $result=$gtm->apply($account,$preview['ticket']);verifyGtm($result['verification']==='confirmed' && $result['resource']['Ttl']===90,'Apply and verify');
 $same=$gtm->apply($account,$preview['ticket']);verifyGtm($same===$result,'Idempotent local confirmation');
 try{$gtm->preview($account,'UpdateCloudGtmInstanceConfigBasic',['ConfigId'=>'config-1','Ttl'=>120],$detail['token']);throw new LogicException('Stale edit accepted');}catch(ApiError $e){verifyGtm($e->status===409,'Conflict status');}
 $pool=$gtm->detail($account,'pools','pool-1');
 try{$gtm->preview($account,'DeleteCloudGtmAddressPool',['AddressPoolId'=>'pool-1'],$pool['token']);throw new LogicException('Referenced deletion accepted');}catch(ApiError $e){verifyGtm($e->status===409,'Delete guard');}
 $noTasks=$gtm->preview($account,'CreateCloudGtmAddress',['Name'=>'No health tasks','Type'=>'IPv4','Address'=>'192.0.2.1','EnableStatus'=>'disable','AvailableMode'=>'auto'],null);
 verifyGtm(!isset($noTasks['parameters']['HealthJudgement']),'Creation without health tasks must not infer a judgement');
 try{$gtm->preview($account,'CreateCloudGtmAddress',['Name'=>'Missing judgement','Type'=>'IPv4','Address'=>'192.0.2.1','EnableStatus'=>'disable','AvailableMode'=>'auto','HealthTasks'=>[['TemplateId'=>'mtp-1','Port'=>443]]],null);throw new LogicException('Creation with tasks needs explicit health judgement');}catch(ApiError $e){verifyGtm(str_contains($e->getMessage(),'健康判定条件'),'Conditional creation validation');}
 $p=$gtm->preview($account,'ReplaceCloudGtmAddressPoolAddress',['AddressPoolId'=>'pool-1','Addresses'=>[['AddressId'=>'addr-1','WeightValue'=>20,'SerialNumber'=>1,'RequestSource'=>['default']]]],$pool['token']);
 $r=$gtm->apply($account,$p['ticket']);verifyGtm($r['verification']==='confirmed','Nested association verification');
 $raw=json_decode(substr(file_get_contents($directory.'/gtm-fixture.php'),strlen(Store::GUARD)),true);$calls=array_values(array_filter($raw['calls'],fn($q)=>$q['Action']==='ReplaceCloudGtmAddressPoolAddress'));
 verifyGtm(is_string($calls[0]['Addresses']) && json_decode($calls[0]['Addresses'],true)[0]['WeightValue']===20 && !isset($calls[0]['Addresses.1.AddressId']),'Official JSON-style RPC encoding');
 try{$gtm->preview($account,'CreateCloudGtmAddress',['Name'=>'bad','Type'=>'IPv4','Address'=>'not-an-ip','EnableStatus'=>'disable','AvailableMode'=>'auto','HealthJudgement'=>'any_ok'],null);throw new LogicException('Invalid IP accepted');}catch(ApiError){}
 $p=$gtm->preview($account,'CreateCloudGtmAddressPool',['AddressPoolName'=>'New pool','AddressPoolType'=>'IPv6','EnableStatus'=>'disable','HealthJudgement'=>'any_ok'],null);$created=$gtm->apply($account,$p['ticket']);
 verifyGtm($created['resource_id']!=='' && $created['verification']==='confirmed','Create resource');
 $d=$gtm->detail($account,'pools',$created['resource_id']);$delete=$gtm->preview($account,'DeleteCloudGtmAddressPool',['AddressPoolId'=>$created['resource_id']],$d['token']);$gtm->apply($account,$delete['ticket']);
 $d=$gtm->detail($account,'addresses','addr-1');$p=$gtm->preview($account,'UpdateCloudGtmAddressRemark',['AddressId'=>'addr-1','Remark'=>'next'],$d['token']);
 $raw=json_decode(substr(file_get_contents($directory.'/gtm-fixture.php'),strlen(Store::GUARD)),true);$raw['addresses']['addr-1']['Remark']='external edit';file_put_contents($directory.'/gtm-fixture.php',Store::GUARD.json_encode($raw));
 try{$gtm->apply($account,$p['ticket']);throw new LogicException('External edit overwritten');}catch(ApiError $e){verifyGtm($e->status===409,'Apply recheck');}
 $tampered=$p['ticket'];$tampered[5]=$tampered[5]==='a'?'b':'a';try{$gtm->apply($account,$tampered);throw new LogicException('Tampered ticket accepted');}catch(RuntimeException){}
 $detail=$gtm->detail($account,'monitors','mtp-1');$p=$gtm->preview($account,'UpdateCloudGtmMonitorTemplate',['TemplateId'=>'mtp-1','Interval'=>30,'IspCityNodes'=>[['CityCode'=>'503','IspCode'=>'465']],'ExtendInfo'=>'{"host":"api.unit.test","path":"/ready","code":500,"sni":true,"followRedirect":false}'],$detail['token']);verifyGtm($gtm->apply($account,$p['ticket'])['verification']==='confirmed','Monitor forms and nested selectors');
 verifyGtm(Gtm::fingerprint(['Name'=>'a','HealthStatus'=>'ok','UpdateTimestamp'=>1])===Gtm::fingerprint(['Name'=>'a','HealthStatus'=>'exceptional','UpdateTimestamp'=>2]),'Health changes do not create edit conflicts');
 $minimal=$gtm->options($account,'UpdateCloudGtmInstanceConfigBasic');verifyGtm(array_keys($minimal)===['errors'],'Basic edit must not query unrelated resource catalogs');
 $originalTransport=Http::$transport;$attempts=0;
 Http::$transport=function($method,$url,$headers,$body)use($originalTransport,&$attempts){parse_str(parse_url($url,PHP_URL_QUERY)??'',$q);if(($q['Action']??'')==='DescribeCloudGtmAddress' && ++$attempts===1)throw new RuntimeException('network timeout (28)');return $originalTransport($method,$url,$headers,$body);};
 $gtm->detail($account,'addresses','addr-1');verifyGtm($attempts===2,'Transient read timeout must retry once');Http::$transport=$originalTransport;
 $detail=$gtm->detail($account,'addresses','addr-1');$preview=$gtm->preview($account,'UpdateCloudGtmAddressRemark',['AddressId'=>'addr-1','Remark'=>'timeout test'],$detail['token']);$writes=0;
 Http::$transport=function($method,$url,$headers,$body)use($originalTransport,&$writes){if($method==='POST'){++$writes;throw new RuntimeException('network timeout (28)');}return $originalTransport($method,$url,$headers,$body);};
 try{$gtm->apply($account,$preview['ticket']);throw new LogicException('Timeout not reported');}catch(RuntimeException){}
 verifyGtm($writes===1,'Never auto-retry mutation');Http::$transport=$originalTransport;
 $d=$gtm->detail($account,'addresses','addr-1');
 $batch=$gtm->previewBatch($account,[['operation'=>'UpdateCloudGtmAddressRemark','parameters'=>['AddressId'=>'addr-1','Remark'=>'batch remark']],['operation'=>'UpdateCloudGtmAddressEnableStatus','parameters'=>['AddressId'=>'addr-1','EnableStatus'=>'disable']]],$d['token']);
 $saved=$gtm->apply($account,$batch['ticket']);verifyGtm($saved['verification']==='confirmed' && count($saved['steps'])===2,'Unified edit must apply all steps with updated conflict tokens');
 verifyGtm($gtm->apply($account,$batch['ticket'])===$saved,'Unified edit replay must not repeat writes');
 $d=$gtm->detail($account,'addresses','addr-1');verifyGtm($d['resource']['Remark']==='batch remark' && $d['resource']['EnableStatus']==='disable','Unified edit persisted');
 try{$gtm->previewBatch($account,[['operation'=>'DeleteCloudGtmAddress','parameters'=>['AddressId'=>'addr-1']]],$d['token']);throw new LogicException('Batch delete accepted');}catch(ApiError){}
 $batch=$gtm->previewBatch($account,[['operation'=>'UpdateCloudGtmAddressRemark','parameters'=>['AddressId'=>'addr-1','Remark'=>'partial remark']],['operation'=>'UpdateCloudGtmAddressEnableStatus','parameters'=>['AddressId'=>'addr-1','EnableStatus'=>'enable']]],$d['token']);
 Http::$transport=function($method,$url,$headers,$body)use($originalTransport){parse_str(parse_url($url,PHP_URL_QUERY)??'',$q);if(($q['Action']??'')==='UpdateCloudGtmAddressEnableStatus')throw new RuntimeException('simulated write failure');return $originalTransport($method,$url,$headers,$body);};
 $partial=$gtm->apply($account,$batch['ticket']);verifyGtm($partial['partial'] && count($partial['steps'])===1 && str_contains($partial['error_details']['original_message'] ?? '', 'simulated'),'Partial failure must stop and report completed steps');Http::$transport=$originalTransport;
 $calls=function()use($directory){$state=json_decode(substr(file_get_contents($directory.'/gtm-fixture.php'),strlen(Store::GUARD)),true);return $state['calls'];};
 $start=count($calls());$freshGtm=new Gtm($cloud,$store,$security);$freshGtm->detail($account,'list','config-1','gtm-1');
 $actual=array_column(array_slice($calls(),$start),'Action');verifyGtm($actual===['DescribeCloudGtmInstanceConfigFullInfo'],'Canvas detail with known instance must avoid extra list queries');
 $start=count($calls());$gtm->options($account,'UpdateCloudGtmInstanceConfigBasic',true,'lines');
 verifyGtm(array_column(array_slice($calls(),$start),'Action')===['DescribeCloudGtmSystemLines'],'Lazy line options must not query other resource catalogs');
 $d=$gtm->detail($account,'addresses','addr-1');$start=count($calls());
 $fast=$gtm->previewBatch($account,[['operation'=>'UpdateCloudGtmAddress','parameters'=>['AddressId'=>'addr-1','Name'=>'fast name']],['operation'=>'UpdateCloudGtmAddressEnableStatus','parameters'=>['AddressId'=>'addr-1','EnableStatus'=>'enable']],['operation'=>'UpdateCloudGtmAddressRemark','parameters'=>['AddressId'=>'addr-1','Remark'=>'fast remark']]],$d['token']);
 verifyGtm(array_column(array_slice($calls(),$start),'Action')===['DescribeCloudGtmAddress','DescribeCloudGtmAddressReference'],'Three-operation preview must read the resource and references just once');
 $start=count($calls());$gtm->preview($account,'UpdateCloudGtmAddress',['AddressId'=>'addr-1','Address'=>'192.0.2.10'],$d['token']);
 verifyGtm(array_column(array_slice($calls(),$start),'Action')===['DescribeCloudGtmAddress','DescribeCloudGtmAddressReference'],'Address type and conflict checks must share one fresh read');
 $start=count($calls());$gtm->preview($account,'UpdateCloudGtmAddressRemark',['AddressId'=>'addr-1','Remark'=>'fresh read'],$d['token']);
 verifyGtm(count($calls())-$start===2,'Read coalescing must never survive into the next preview');
 $raw=json_decode(substr(file_get_contents($directory.'/gtm-fixture.php'),strlen(Store::GUARD)),true);$raw['addresses']['addr-1']['Name']='externally changed after preview';file_put_contents($directory.'/gtm-fixture.php',Store::GUARD.json_encode($raw));
 try{$gtm->apply($account,$fast['ticket']);throw new LogicException('Coalesced preview bypassed apply conflict check');}catch(ApiError $e){verifyGtm($e->status===409,'Fresh conflict check still required at apply');}
 $metrics=$gtm->metrics();verifyGtm($metrics['cloud_calls']>0 && $metrics['cloud_ms']>=0,'Safe timing metadata');
 $d=$gtm->detail($account,'addresses','addr-1');
 $missing=json_decode(substr(file_get_contents($directory.'/gtm-fixture.php'),strlen(Store::GUARD)),true);
 $savedCondition=$missing['addresses']['addr-1']['HealthJudgement'];
 unset($missing['addresses']['addr-1']['HealthJudgement']);file_put_contents($directory.'/gtm-fixture.php',Store::GUARD.json_encode($missing));
 $withoutCondition=$gtm->detail($account,'addresses','addr-1');
 try{$gtm->preview($account,'UpdateCloudGtmAddress',['AddressId'=>'addr-1','HealthTasks'=>[['TemplateId'=>'mtp-1','Port'=>30001]]],$withoutCondition['token']);throw new LogicException('Missing cloud health judgement silently defaulted');}catch(ApiError $e){verifyGtm(str_contains($e->getMessage(),'健康判定条件'),'Missing condition must require explicit selection');}
 $missing['addresses']['addr-1']['HealthJudgement']=$savedCondition;file_put_contents($directory.'/gtm-fixture.php',Store::GUARD.json_encode($missing));
 $d=$gtm->detail($account,'addresses','addr-1');
 $associationOnly=$gtm->preview($account,'UpdateCloudGtmAddress',['AddressId'=>'addr-1','HealthTasks'=>[['TemplateId'=>'mtp-1','Port'=>30001]]],$d['token']);
 verifyGtm($associationOnly['parameters']['Name']===$d['resource']['Name'] && $associationOnly['parameters']['Address']===$d['resource']['Address'],'Association-only updates must freeze current name and address to match successful console requests');
 $clearTasks=$gtm->preview($account,'UpdateCloudGtmAddress',['AddressId'=>'addr-1','HealthTasks'=>[]],$d['token']);
 verifyGtm($clearTasks['parameters']['HealthTasks']===[] && $clearTasks['parameters']['Address']===$d['resource']['Address'],'Removing all tasks must preserve address information');
 // Regression: an IP-only edit must preserve the existing health check, not send only Name/Address.
 $raw=json_decode(substr(file_get_contents($directory.'/gtm-fixture.php'),strlen(Store::GUARD)),true);
 $raw['addresses']['addr-1']['AvailableMode']='manual';$raw['addresses']['addr-1']['ManualAvailableStatus']='unavailable';file_put_contents($directory.'/gtm-fixture.php',Store::GUARD.json_encode($raw));
 $d=$gtm->detail($account,'addresses','addr-1');
 $ipOnly=$gtm->preview($account,'UpdateCloudGtmAddress',['AddressId'=>'addr-1','Address'=>'200.180.161.87','Name'=>$d['resource']['Name']],$d['token']);
 verifyGtm($ipOnly['parameters']['HealthTasks']===[['TemplateId'=>'mtp-1','Port'=>443]] && $ipOnly['parameters']['HealthJudgement']==='any_ok','IP-only edit must freeze existing task IDs, ports and judgement, stripping read-only names/status');
 verifyGtm(!isset($ipOnly['parameters']['AvailableMode']) && !isset($ipOnly['parameters']['ManualAvailableStatus']),'Base update must not change failover mode');
 $legacy=json_decode($security->decrypt($ipOnly['ticket']),true);unset($legacy['parameters']['HealthTasks']);
 try{$gtm->apply($account,$security->encrypt(json_encode($legacy)));throw new LogicException('Incomplete old ticket submitted');}catch(ApiError $e){verifyGtm($e->status===409,'Old ticket must be regenerated rather than silently changed');}
 Http::$transport=function($method,$url,$headers,$body)use($originalTransport){parse_str(parse_url($url,PHP_URL_QUERY)??'',$q);if(($q['Action']??'')==='UpdateCloudGtmAddress' && (!isset($q['HealthTasks'],$q['HealthJudgement'])))return ['status'=>400,'body'=>json_encode(['Code'=>'ParamsError','Message'=>'params error.'])];return $originalTransport($method,$url,$headers,$body);};
 $saved=$gtm->apply($account,$ipOnly['ticket']);verifyGtm($saved['verification']==='confirmed' && $saved['resource']['AvailableMode']==='manual' && $saved['resource']['ManualAvailableStatus']==='unavailable','IP-only update verifies health preservation without changing manual state');
 Http::$transport=$originalTransport;$d=$gtm->detail($account,'addresses','addr-1');
 $paired=$gtm->preview($account,'UpdateCloudGtmAddress',['AddressId'=>'addr-1','Address'=>'130.12.18.223','HealthTasks'=>[['TemplateId'=>'mtp-1','Port'=>30001]]],$d['token']);
 verifyGtm($paired['parameters']['Address']==='130.12.18.223' && $paired['parameters']['Name']===$d['resource']['Name'],'Explicit address edits must not be replaced by baseline values');
 verifyGtm($paired['parameters']['HealthJudgement']===$d['resource']['HealthJudgement'],'Health task changes must freeze the preserved health judgement into preview');
 Http::$transport=function($method,$url,$headers,$body)use($originalTransport){parse_str(parse_url($url,PHP_URL_QUERY)??'',$q);if(($q['Action']??'')==='UpdateCloudGtmAddress' && (empty($q['Name']) || empty($q['Address']) || (!empty(json_decode($q['HealthTasks']??'[]',true)) && empty($q['HealthJudgement']))))return ['status'=>400,'body'=>json_encode(['Code'=>'CloudGtm.Address.HealthJudgementInvalid','Message'=>'params error.','RequestId'=>'request-health-required'])];return $originalTransport($method,$url,$headers,$body);};
 verifyGtm($gtm->apply($account,$paired['ticket'])['verification']==='confirmed','Address and new health checks must save together with their dependent condition');
 Http::$transport=$originalTransport;$d=$gtm->detail($account,'addresses','addr-1');$failed=$gtm->preview($account,'UpdateCloudGtmAddress',['AddressId'=>'addr-1','Name'=>'rejected'],$d['token']);$rejectedWrites=0;
 Http::$transport=function($method,$url,$headers,$body)use($originalTransport,&$rejectedWrites){if($method==='POST'){++$rejectedWrites;return ['status'=>400,'body'=>json_encode(['Code'=>'CloudGtm.Address.HealthJudgementInvalid','Message'=>'params error.','RequestId'=>'request-localized'])];}return $originalTransport($method,$url,$headers,$body);};
 try{$gtm->apply($account,$failed['ticket']);throw new LogicException('Cloud error not returned');}catch(\CloudDesk\GtmRequestError $e){verifyGtm(str_contains($e->getMessage(),'健康判定条件') && !str_contains($e->getMessage(),'params error'),'Primary GTM error must be localized');verifyGtm($e->details['code']==='CloudGtm.Address.HealthJudgementInvalid' && $e->details['request_id']==='request-localized' && $e->details['http_status']===400 && str_contains($e->details['original_message'],'params error'),'Provider diagnosis must be preserved');verifyGtm(json_decode($e->details['request_parameters'],true)['Name']==='rejected','Actual business parameters must be available for diagnosis');verifyGtm(!str_contains($e->details['request_parameters'],'ClientToken') && !str_contains($e->details['request_parameters'],'Signature') && !str_contains($e->details['request_parameters'],'AccessKey'),'Diagnostics must exclude RPC credentials and tokens');verifyGtm(json_decode($e->details['address_context'],true)['Type']==='IPv4','Failed address writes must retain baseline health context');}
 verifyGtm($rejectedWrites===1,'HTTP parameter rejection must never replay a mutation');Http::$transport=$originalTransport;
 $unknown=Gtm::requestError('UpdateCloudGtmAddress',new \CloudDesk\CloudResponseError('params error.','InvalidParameter','request-unknown',400));verifyGtm(str_contains($unknown->getMessage(),'请求参数') && !str_contains($unknown->getMessage(),'健康判定条件'),'Unknown parameter errors must not invent a specific cause');
 echo "PASS: GTM topology, selectors, JSON RPC bindings, create/update/delete, references, stale edits, preview integrity, idempotency and verification\n";
} finally {
 Http::$transport=null;
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($files as$file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($directory);
}

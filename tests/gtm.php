<?php

declare(strict_types=1);
foreach (['Store','Security','Cloud','Objects','Configs','Plans','Checks','Releases','Gtm','Importer','Api'] as $name) require __DIR__.'/../_core/'.$name.'.php';
require __DIR__.'/gtm-fixture.php';
use CloudDesk\{Store,Security,Cloud,Http,Gtm,ApiError};
function verifyGtm(bool $ok,string $why): void { if(!$ok)throw new RuntimeException($why); }
$directory=sys_get_temp_dir().'/gtm-test-'.bin2hex(random_bytes(8));putenv('CLOUD_DESK_DATA='.$directory);
$store=new Store($directory);$security=new Security($store);$cloud=new Cloud($security);$gtm=new Gtm($cloud,$store,$security);
$account=['id'=>1,'provider'=>'aliyun','enabled'=>true,'credentials'=>['key'=>$security->encrypt('fake'),'secret'=>$security->encrypt('fake')]];
Http::$transport=function($method,$url,$headers,$body){parse_str($method==='POST'?$body:(parse_url($url,PHP_URL_QUERY)??''),$query);
 if($method==='POST') {
  verifyGtm(($headers['Content-Type']??'')==='application/x-www-form-urlencoded','GTM mutation form content type');
  $signature=$query['Signature'];$unsigned=$query;unset($unsigned['Signature']);ksort($unsigned);
  $expected=base64_encode(hash_hmac('sha1','POST&%2F&'.rawurlencode(http_build_query($unsigned,'','&',PHP_QUERY_RFC3986)),'fake&',true));
  verifyGtm(hash_equals($expected,$signature),'Signed RPC method must match POST');
 }
 return gtmFixture($query);};
try {
 $list=$gtm->listing($account,'list');verifyGtm(count($list['items'])===1 && $list['items'][0]['ConfigId']==='config-1','Typed list extraction');
 $detail=$gtm->detail($account,'list','config-1');verifyGtm($detail['resource']['AddressPools']['AddressPool'][0]['Addresses']['Address'][0]['AddressId']==='addr-1','Complete topology');
 $options=$gtm->options($account);verifyGtm(count($options['instances'])===1 && $options['nodes'] && !$options['errors'],'Resource selectors');
 $preview=$gtm->preview($account,'UpdateCloudGtmInstanceConfigBasic',['ConfigId'=>'config-1','InstanceId'=>'gtm-1','Ttl'=>90],$detail['token']);
 $result=$gtm->apply($account,$preview['ticket']);verifyGtm($result['verification']==='confirmed' && $result['resource']['Ttl']===90,'Apply and verify');
 $same=$gtm->apply($account,$preview['ticket']);verifyGtm($same===$result,'Idempotent local confirmation');
 try{$gtm->preview($account,'UpdateCloudGtmInstanceConfigBasic',['ConfigId'=>'config-1','Ttl'=>120],$detail['token']);throw new LogicException('Stale edit accepted');}catch(ApiError $e){verifyGtm($e->status===409,'Conflict status');}
 $pool=$gtm->detail($account,'pools','pool-1');
 try{$gtm->preview($account,'DeleteCloudGtmAddressPool',['AddressPoolId'=>'pool-1'],$pool['token']);throw new LogicException('Referenced deletion accepted');}catch(ApiError $e){verifyGtm($e->status===409,'Delete guard');}
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
 echo "PASS: GTM topology, selectors, JSON RPC bindings, create/update/delete, references, stale edits, preview integrity, idempotency and verification\n";
} finally {
 Http::$transport=null;
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($files as$file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($directory);
}

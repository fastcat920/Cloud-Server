<?php

declare(strict_types=1);
foreach (['Store','Security','Cloud','Objects','Configs','Plans','Checks','Releases','Gtm','Importer','Api'] as $name) require __DIR__.'/../_core/'.$name.'.php';
require __DIR__.'/gtm-fixture.php';
use CloudDesk\{Store,Security,Cloud,Http,Gtm,ApiError};
function isolation(bool $ok,string $why): void { if(!$ok)throw new RuntimeException($why); }
$directory=sys_get_temp_dir().'/gtm-isolation-'.bin2hex(random_bytes(8));putenv('CLOUD_DESK_DATA='.$directory);
$store=new Store($directory);$security=new Security($store);$gtm=new Gtm(new Cloud($security),$store,$security);
$account=['id'=>1,'provider'=>'aliyun','enabled'=>true,'credentials'=>['key'=>$security->encrypt('fake'),'secret'=>$security->encrypt('fake')]];
Http::$transport=function($method,$url,$headers,$body){parse_str(parse_url($url,PHP_URL_QUERY)??'',$query);return gtmFixture($query);};
$path=$directory.'/gtm-fixture.php';
$read=fn()=>json_decode(substr(file_get_contents($path),strlen(Store::GUARD)),true);
$write=function($state)use($path){file_put_contents($path,Store::GUARD.json_encode($state,JSON_THROW_ON_ERROR));};
$apply=function(string $op,string $kind,string $id,array $params)use($gtm,$account){$d=$gtm->detail($account,$kind,$id);$p=$gtm->preview($account,$op,$params,$d['token']);return $gtm->apply($account,$p['ticket']);};
try {
 $gtm->listing($account,'list');$s=$read();
 $s['addresses']['addr-2']=array_merge($s['addresses']['addr-1'],['AddressId'=>'addr-2','Name'=>'Second','Address'=>'192.0.2.20']);
 $s['pools']['pool-1']['Addresses']=[['AddressId'=>'addr-1','WeightValue'=>17,'SerialNumber'=>2,'RequestSource'=>['cn']],['AddressId'=>'addr-2','WeightValue'=>23,'SerialNumber'=>1,'RequestSource'=>['default']]];
 $s['pools']['pool-2']=array_merge($s['pools']['pool-1'],['AddressPoolId'=>'pool-2','AddressPoolName'=>'Other pool','Addresses'=>[['AddressId'=>'addr-1','WeightValue'=>71,'SerialNumber'=>1,'RequestSource'=>['default']]]]);
 $s['list']['config-2']=array_merge($s['list']['config-1'],['ConfigId'=>'config-2','ScheduleDomainName'=>'other.unit.test']);
 $s['list']['config-1']['AddressPools']=[['AddressPoolId'=>'pool-1','WeightValue'=>19,'SerialNumber'=>2,'RequestSource'=>['cn']],['AddressPoolId'=>'pool-2','WeightValue'=>81,'SerialNumber'=>1,'RequestSource'=>['default']]];
 $write($s);$baseline=$read();
 // Reorder addresses by persisted priority; preserve weights, lines and every other resource.
 $bindings=array_reverse($s['pools']['pool-1']['Addresses']);foreach($bindings as$i=>&$item)$item['SerialNumber']=$i+1;unset($item);
 $r=$apply('ReplaceCloudGtmAddressPoolAddress','pools','pool-1',['AddressPoolId'=>'pool-1','Addresses'=>$bindings]);isolation($r['verification']==='confirmed','Address order read-back');
 $next=$read();isolation($next['pools']['pool-2']===$baseline['pools']['pool-2'] && $next['addresses']===$baseline['addresses'] && $next['list']===$baseline['list'],'Address sorting isolation');
 isolation($next['pools']['pool-1']['Addresses'][0]['WeightValue']===23 && $next['pools']['pool-1']['Addresses'][1]['RequestSource']===['cn'],'Address metadata retained');
 // Detach one address. Never issue DeleteCloudGtmAddress and never mutate other pools.
 $before=$read();$remaining=[$bindings[1]];
 $apply('ReplaceCloudGtmAddressPoolAddress','pools','pool-1',['AddressPoolId'=>'pool-1','Addresses'=>$remaining]);$next=$read();
 isolation(isset($next['addresses']['addr-2']) && $next['addresses']===$before['addresses'] && $next['pools']['pool-2']===$before['pools']['pool-2'],'Detach retains global addresses');
 // Re-add it and preserve the existing member exactly.
 $apply('ReplaceCloudGtmAddressPoolAddress','pools','pool-1',['AddressPoolId'=>'pool-1','Addresses'=>[$remaining[0],$bindings[0]]]);$next=$read();
 isolation($next['pools']['pool-1']['Addresses'][0]===$remaining[0] && $next['pools']['pool-2']===$baseline['pools']['pool-2'],'Add association preserves existing fields');
 // Reorder pools on only one config, then detach a shared pool from that config.
 $before=$read();$pools=array_reverse($before['list']['config-1']['AddressPools']);foreach($pools as$i=>&$item)$item['SerialNumber']=$i+1;unset($item);
 $r=$apply('ReplaceCloudGtmInstanceConfigAddressPool','list','config-1',['ConfigId'=>'config-1','AddressPools'=>$pools]);isolation($r['verification']==='confirmed','Pool order read-back');
 $next=$read();isolation($next['list']['config-2']===$before['list']['config-2'] && $next['pools']===$before['pools'] && $next['addresses']===$before['addresses'],'Pool sorting isolation');
 $apply('ReplaceCloudGtmInstanceConfigAddressPool','list','config-1',['ConfigId'=>'config-1','AddressPools'=>[$pools[0]]]);$next=$read();
 isolation(isset($next['pools']['pool-1']) && $next['list']['config-2']===$before['list']['config-2'],'Detach retains global pool and other config');
 $d=$gtm->detail($account,'pools','pool-1');try{$gtm->preview($account,'DeleteCloudGtmAddressPool',['AddressPoolId'=>'pool-1'],$d['token']);throw new LogicException('Shared pool deleted');}catch(ApiError $e){isolation($e->status===409,'Referenced delete refused');}
 // A global health-template edit is intentionally shared; address bindings stay intact.
 $before=$read();$r=$apply('UpdateCloudGtmMonitorTemplate','monitors','mtp-1',['TemplateId'=>'mtp-1','Interval'=>30,'Timeout'=>3000,'EvaluationCount'=>2,'FailureRate'=>80,'IspCityNodes'=>[['CityCode'=>'503','IspCode'=>'465']],'ExtendInfo'=>'{"host":"health.unit.test","path":"/ready","code":400,"sni":true,"followRedirect":true}']);
 isolation($r['verification']==='confirmed','Monitor settings read-back');$next=$read();
 isolation($next['addresses']===$before['addresses'] && $next['pools']===$before['pools'] && $next['list']===$before['list'],'Template edit preserves unrelated configurations and bindings');
 isolation(count($gtm->references($account,'monitors','mtp-1'))===2,'Shared monitor impact count');
 // Change one address's health port. Another address and all pool associations must stay unchanged.
 $before=$read();$r=$apply('UpdateCloudGtmAddress','addresses','addr-1',['AddressId'=>'addr-1','HealthJudgement'=>'all_ok','HealthTasks'=>[['TemplateId'=>'mtp-1','Port'=>8443]]]);$next=$read();
 isolation($r['verification']==='confirmed' && $next['addresses']['addr-1']['HealthTasks'][0]['Port']===8443,'Health port and judgement read-back');
 isolation($next['addresses']['addr-2']===$before['addresses']['addr-2'] && $next['pools']===$before['pools'] && $next['monitors']===$before['monitors'],'Health binding isolation');
 // Deleting a pool after removing all parent references must preserve its addresses and templates.
 $apply('ReplaceCloudGtmInstanceConfigAddressPool','list','config-2',['ConfigId'=>'config-2','AddressPools'=>[]]);$before=$read();
 $apply('DeleteCloudGtmAddressPool','pools','pool-1',['AddressPoolId'=>'pool-1']);$next=$read();
 isolation(!isset($next['pools']['pool-1']) && $next['addresses']===$before['addresses'] && $next['monitors']===$before['monitors'] && $next['pools']['pool-2']===$before['pools']['pool-2'],'Global pool deletion does not cascade');
 $before=$read();$apply('DeleteCloudGtmAddress','addresses','addr-2',['AddressId'=>'addr-2']);$next=$read();
 isolation(!isset($next['addresses']['addr-2']) && $next['addresses']['addr-1']===$before['addresses']['addr-1'] && $next['pools']===$before['pools'] && $next['monitors']===$before['monitors'],'Deleting unreferenced address preserves other resources');
 echo "PASS: shared-resource isolation, add/detach/delete safety, pool/address priority and metadata preservation, monitor/port save and impact boundaries\n";
} finally {
 Http::$transport=null;$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($files as$file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($directory);
}

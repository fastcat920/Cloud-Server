<?php

declare(strict_types=1);
foreach (['Store','Security','Cloud','Objects','Configs','Plans','Checks','Releases','Gtm','Importer','Api'] as $name) require __DIR__.'/../_core/'.$name.'.php';
use CloudDesk\{Store,Security,Cloud,Http,Objects,Configs,Plans,Checks,Releases,ApiError};
function ensure(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
$directory=sys_get_temp_dir().'/cloud-reliable-'.bin2hex(random_bytes(8));
$store=new Store($directory);$security=new Security($store);$objects=new Objects(new Cloud($security));$configs=new Configs($security);$releases=new Releases($store,$security,$objects,$configs);
try {
 $schema=['type'=>'object','properties'=>['value'=>['type'=>'integer']],'additionalProperties'=>true];
 $store->transaction(function(array &$s) use ($security,$schema) {
  $s['accounts']=[['id'=>1,'name'=>'test','provider'=>'aliyun','enabled'=>true,'credentials'=>['key'=>$security->encrypt('fake'),'secret'=>$security->encrypt('fake')]]];
  $s['targets']=[['id'=>1,'account_id'=>1,'name'=>'test','region'=>'cn-hangzhou','bucket'=>'test-bucket','key'=>'folder.v1/config','enabled'=>true,'public_read'=>false]];
  $s['templates']=[['id'=>1,'schema'=>$schema]];$s['plans']=[['id'=>1,'template_id'=>1,'targets'=>[1]]];
  $s['versions']=[['id'=>1,'version'=>1,'revision'=>1,'plan_id'=>1,'template_id'=>1,'schema'=>$schema,'content'=>['value'=>1],'mode'=>'xor_base64','status'=>'draft'],['id'=>2,'version'=>2,'revision'=>2,'plan_id'=>1,'template_id'=>1,'schema'=>$schema,'content'=>['value'=>2],'mode'=>'plain','status'=>'draft']];
  $s['settings']['xor_key']=$security->encrypt('original-key');
 });
 $files=[];$fail=true;$blocked=false;
 Http::$transport=function($method,$url,$headers,$body) use (&$files,&$fail,&$blocked,$releases) {
  $path=parse_url($url,PHP_URL_PATH);
  if($method==='PUT') {
   if($path==='/folder.v1/config') {
    try {$releases->run(2,[1]);}catch(ApiError $e){$blocked=$e->status===409;}
   }
   $files[$path]=$body;return ['status'=>200,'body'=>''];
  }
  if($fail){$fail=false;throw new RuntimeException('simulated timeout');}
  return ['status'=>200,'body'=>$files[$path]??''];
 };
 $result=$releases->run(1,[1]);ensure($result[0]['status']==='failed' && $blocked,'Concurrent releases must be rejected');
 ensure(!isset($result[0]['frozen']),'Never return frozen credentials');
 $job=$store->read()['publications'][0];$expected=$files['/folder.v1/config'];
 ensure(str_starts_with(array_keys($files)[0],'/folder.v1/config.release-'),'Version suffix must not modify dotted directory');
 $store->transaction(function(array &$s)use($security){$s['settings']['xor_key']=$security->encrypt('changed-key');});
 $result=$releases->run(0,[],$job['id']);ensure($result[0]['status']==='success' && $files['/folder.v1/config']===$expected,'Retry must preserve frozen output across key rotation');
 $releases->run(2,[1]);
 try{$releases->run(0,[],$job['id']);throw new LogicException('Stale retry accepted');}catch(ApiError $e){ensure($e->status===409,'Stale retry conflict');}
 $store->transaction(function(array &$s){$s['publications'][0]['status']='publishing';$s['versions'][0]['status']='publishing';});
 $lock=$releases->lock();$releases->recover();ensure($store->read()['publications'][0]['status']==='publishing','Live lock must prevent interruption recovery');fclose($lock);
 $releases->recover();ensure($store->read()['publications'][0]['status']==='failed','Abandoned publication must recover');
 $state=$store->read();Checks::state($state);
 $bad=$state;$bad['accounts'][]=$bad['accounts'][0];
 try{Checks::state($bad);throw new LogicException('Duplicate accepted');}catch(ApiError){}
 $bad=$state;$bad['plans'][0]['template_id']=999;
 try{Checks::state($bad);throw new LogicException('Dangling reference accepted');}catch(RuntimeException){}
 $v=['schema'=>$schema,'content'=>['value'=>1]];$token=Checks::token($v);$v['field_order']=['[]'=>['value']];ensure($token!==Checks::token($v),'Order is part of edit token');
 $sample=['schema'=>['type'=>'object','properties'=>[]],'content'=>Configs::defaults(),'oss_validation'=>true];
 try{Configs::preflight($sample);throw new LogicException('Example publication accepted');}catch(RuntimeException $e){ensure(str_contains($e->getMessage(),'示例'),'Example error');}
 $content=['update'=>['platforms'=>['android'=>['enabled'=>true,'source'=>'direct','latest_version'=>'3.6.3','build'=>9,'artifacts'=>['arm64'=>['url'=>'https://download.unit.test/client.apk','sha256'=>str_repeat('a',64),'size'=>100]]]]]];
 $sample['content']=$content;Configs::preflight($sample);
 $sample['content']['update']['platforms']['android']['artifacts']['arm64']['sha256']='bad';
 try{Configs::preflight($sample);throw new LogicException('Bad SHA accepted');}catch(RuntimeException $e){ensure(str_contains($e->getMessage(),'SHA256'),'SHA error');}
 try{Plans::checkSchema(['type'=>'object','properties'=>['v'=>['type'=>'integer','default'=>'bad']]]);throw new LogicException('Invalid default accepted');}catch(RuntimeException){}
 echo "PASS: publication locking, interrupted recovery, frozen retry, stale-task protection, immutable paths, backup relationships, tokens and preflight\n";
} finally {
 Http::$transport=null;
 $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($files as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($directory);
}

<?php
declare(strict_types=1);
foreach(['Store','Security','Cloud','Notifications','Api'] as $file)require_once __DIR__.'/../_core/'.$file.'.php';
use CloudDesk\{Store,Security,Http,Notifications,ApiError};
function ensureN(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
$dir=sys_get_temp_dir().'/notification-test-'.bin2hex(random_bytes(8));$store=new Store($dir);$security=new Security($store);$n=new Notifications($store,$security);
$token='123456:'.str_repeat('a',32);$sent=[];
Http::$transport=function($method,$url,$headers,$body)use(&$sent,$token){ensureN($method==='POST' && $url==='https://api.telegram.org/bot'.$token.'/sendMessage','Fixed Telegram endpoint');$sent[]=json_decode($body,true);return ['status'=>200,'body'=>'{"ok":true}'];};
try{
 $channel=$n->saveChannel(['name'=>'TG','chat_id'=>'-100123456','token'=>$token,'enabled'=>true,'thread_id'=>'123']);
 $source=$n->saveSource(['name'=>'GTM','channel_id'=>$channel,'enabled'=>true]);$key=$n->listing()['sources'][0]['webhook_key'];
 ensureN(!str_contains(json_encode($store->read()),$token) && !str_contains(json_encode($store->read()),$key),'Credentials encrypted at rest');
 ensureN(!isset($n->listing()['channels'][0]['token']),'Channel token never returned');
 try{$n->receive($source,str_repeat('0',64),['status'=>'OCCURRED']);throw new LogicException('Invalid callback key accepted');}catch(ApiError $e){ensureN($e->status===401,'Callback authorization');}
 $event=['specversion'=>'1.0','id'=>'event-1','subject'=>'GTM 地址异常','status'=>'OCCURRED','alertMessage'=>'地址 192.0.2.1 不可用','resource'=>['entity'=>['entity_id'=>'addr-1']]];
 $first=$n->receive($source,$key,$event);$duplicate=$n->receive($source,$key,array_reverse($event,true));
 ensureN(!$first['duplicate'] && $duplicate['duplicate'],'Canonical payload deduplication');
 $changedTrace=$event;$changedTrace['traceId']='different delivery attempt';ensureN($n->receive($source,$key,$changedTrace)['duplicate'],'Cloud event ID deduplicates changing delivery metadata');
 ensureN($n->process()===1 && count($sent)===1,'One persisted queued delivery');
 ensureN($sent[0]['message_thread_id']===123 && !isset($sent[0]['parse_mode']) && str_contains($sent[0]['text'],'192.0.2.1'),'Plaintext Telegram payload and forum topic');
 ensureN($n->listing()['deliveries'][0]['status']==='sent','Delivery confirmation persisted');
 $event['status']='RESOLVED';$event['id']='event-2';$n->receive($source,$key,$event);$n->process();ensureN(count($sent)===2,'Recovery is a separate event');
 $n->saveChannel(['id'=>$channel,'name'=>'TG','chat_id'=>'-100123456','token'=>'','enabled'=>true,'thread_id'=>'123']);ensureN($security->decrypt($store->read()['notification_channels'][0]['token'])===$token,'Blank token preserves secret');
 Http::$transport=fn()=>['status'=>429,'body'=>'{"ok":false,"error_code":429,"parameters":{"retry_after":120}}'];
 $job=$n->test($channel);ensureN($job['status']==='retry' && $job['next_at']>=time()+119,'Rate limit backoff honored');
 Http::$transport=fn()=>['status'=>400,'body'=>json_encode(['ok'=>false,'error_code'=>400,'description'=>$token])];
 $job=$n->retry($job['id']);ensureN($job['status']==='failed' && !str_contains($job['error'],$token),'Permanent rejection and credential-safe errors');
 Http::$transport=function()use(&$sent){$sent[]=[];return ['status'=>200,'body'=>'{"ok":true}'];};
 ensureN($n->retry($job['id'])['status']==='sent','Explicit retry after fixing destination');
 $n->rotate($source);try{$n->authorize($source,$key);throw new LogicException('Old callback key accepted');}catch(ApiError){}
 $key=$n->listing()['sources'][0]['webhook_key'];
 $pending=$n->receive($source,$key,['text'=>['content'=>'Test DingTalk callback']]);
 $n->saveSource(['id'=>$source,'name'=>'GTM','channel_id'=>$channel,'enabled'=>false]);ensureN($n->listing()['deliveries'][0]['status']==='cancelled','Disabling source cancels pending work');
 try{$n->remove('channel',$channel);throw new LogicException('Referenced channel deleted');}catch(ApiError){}
 $n->remove('source',$source);$n->remove('channel',$channel);
 ensureN(str_contains(Notifications::message(['msgtype'=>'markdown','markdown'=>['text'=>'恢复通知']]),'恢复通知'),'Bot callback format');
 echo "PASS: notification encryption, callback auth, input formats, deduplication, queued send, recovery, backoff, retry, rotation and reference safety\n";
}finally{Http::$transport=null;foreach(glob($dir.'/*')?:[] as $p)if(is_file($p))unlink($p);rmdir($dir);}

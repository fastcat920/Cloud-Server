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
 Http::$transport=fn($method,$url)=>['status'=>200,'body'=>'{"ok":true,"result":{"id":123456,"username":"notice_test_bot","first_name":"Notice"}}'];
 $bot=$n->verify($channel);ensureN($bot['username']==='notice_test_bot' && !isset($bot['token']),'Validate bot identity without exposing token');
 Http::$transport=fn()=>['status'=>401,'body'=>'{"ok":false,"error_code":401,"description":"Unauthorized"}'];
 try{$n->verify($channel);throw new LogicException('Invalid token verified');}catch(ApiError $e){ensureN(str_contains($e->getMessage(),'Bot Token') && str_contains($e->getMessage(),'401'),'Token authentication failure explained');}
 Http::$transport=function($method,$url,$headers,$body)use(&$sent,$token){ensureN(str_ends_with($url,'/sendMessage'),'Send only when explicitly requested');$sent[]=json_decode($body,true);return ['status'=>200,'body'=>'{"ok":true}'];};
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
 // Original alert text must survive envelopes, long messages and partial-send retries.
 $original="  **原始标题**\n状态: OCCURRED\r\n未翻译的字段: value\n  ";
 ensureN(Notifications::message(['msgtype'=>'markdown','markdown'=>['text'=>$original]])===$original,'Original body whitespace and markdown preserved');
 $raw=' { "status": "OCCURRED", "unknown": {"key": 7} } ';
 ensureN(Notifications::message(json_decode($raw,true,512,JSON_THROW_ON_ERROR),$raw)===$raw,'Unknown structured fields and raw JSON spacing preserved');
 $long=str_repeat("原始😀文本\n",1400);
 $chunks=Notifications::parts($long);ensureN(count($chunks)>1 && implode('',$chunks)===$long,'Long Unicode messages split without losing content');
 $deliveries=[];$attempt=0;
 Http::$transport=function($method,$url,$headers,$body)use(&$deliveries,&$attempt){$attempt++;$data=json_decode($body,true);$deliveries[]=$data['text'];if($attempt===2)return ['status'=>429,'body'=>'{"ok":false,"error_code":429,"parameters":{"retry_after":1}}'];return ['status'=>200,'body'=>'{"ok":true}'];};
 $multipart=$n->receive($source,$key,['id'=>'long-original','text'=>['content'=>$long]]);
 $n->process(20,$multipart['id']);$saved=$n->listing()['deliveries'][0];
 ensureN($saved['message']===$long && $saved['part_index']===1 && $saved['status']==='retry','Full original retained and successful first segment checkpointed');
 $n->retry($multipart['id']);$n->process(20,$multipart['id']);
 $saved=$n->listing()['deliveries'][0];ensureN($saved['status']==='sent' && $saved['part_index']===count($chunks),'Remaining original segments delivered');
 ensureN($deliveries[0]===$chunks[0] && $deliveries[1]===$chunks[1] && $deliveries[2]===$chunks[1] && array_slice($deliveries,2)==array_slice($chunks,1),'Retry resumes failed segment without replaying earlier segments');
 $gtm=['alertType'=>'addr_alert','desc'=>'地址异常','domainAlertContent'=>['addressIp'=>'192.0.2.37','addressName'=>'cece','domainName'=>'example.com','instanceName'=>'实例一','time'=>'2026-11-16 23:10:16','timestamp'=>1794841816581],'title'=>'公网全局流量管理 (GTM) 健康检查状态变更通知','userId'=>123456789,'webhook'=>'https://example.invalid/?key=secret-to-exclude'];
 $expected="🔴 GTM 地址异常\n\n域名：example.com\n地址：cece\nIP：192.0.2.37\n时间：2026-11-16 23:10:16\n\n实例：实例一";
 ensureN(Notifications::readable($gtm)===$expected,'Actual GTM payload maps to compact ordered fields without fabricated pool or duplicate metadata');
 $extra=$gtm;$extra['domainAlertContent']['newKey']=['code'=>'unchanged'];$extra['newRoot']='business-data';
 $formatted=Notifications::readable($extra);ensureN(str_contains($formatted,'newKey') && str_contains($formatted,'unchanged') && str_contains($formatted,'business-data') && !str_contains($formatted,'secret-to-exclude') && !str_contains($formatted,'123456789'),'Unknown business data preserved, management fields excluded');
 $unknown=$gtm;$unknown['alertType']='future_event';$unknown['desc']='未知事件说明';ensureN(str_contains(Notifications::readable($unknown),'future_event') && !str_contains(Notifications::readable($unknown),'🟢'),'Unknown event types are never inferred as recovery');
 $n->saveSource(['id'=>$source,'name'=>'GTM','channel_id'=>$channel,'enabled'=>true,'format'=>'original']);
 $raw=json_encode($gtm,JSON_UNESCAPED_UNICODE);$originalJob=$n->receive($source,$key,$gtm,$raw);ensureN($n->listing()['deliveries'][0]['message']===$raw,'Original mode remains available for comparison');
 $n->saveSource(['id'=>$source,'name'=>'GTM','channel_id'=>$channel,'enabled'=>true,'format'=>'readable']);
 $readableJob=$n->receive($source,$key,$gtm,$raw);ensureN(!$readableJob['duplicate'] && $n->listing()['deliveries'][0]['message']===$expected && $n->listing()['deliveries'][0]['content_mode']==='readable','Readable mode applies to new callbacks, with separate format deduplication');
 $n->rotate($source);try{$n->authorize($source,$key);throw new LogicException('Old callback key accepted');}catch(ApiError){}
 $key=$n->listing()['sources'][0]['webhook_key'];
 $pending=$n->receive($source,$key,['text'=>['content'=>'Test DingTalk callback']]);
 $n->saveSource(['id'=>$source,'name'=>'GTM','channel_id'=>$channel,'enabled'=>false]);ensureN($n->listing()['deliveries'][0]['status']==='cancelled','Disabling source cancels pending work');
 try{$n->remove('channel',$channel);throw new LogicException('Referenced channel deleted');}catch(ApiError){}
 $n->remove('source',$source);$n->remove('channel',$channel);
 ensureN(str_contains(Notifications::message(['msgtype'=>'markdown','markdown'=>['text'=>'恢复通知']]),'恢复通知'),'Bot callback format');
 ensureN(str_contains(Notifications::telegramError(400,'Bad Request: chat not found'),'接收目标'),'Unknown chat error localized');
 ensureN(str_contains(Notifications::telegramError(403,'Forbidden: bot was blocked by the user'),'屏蔽'),'Blocked bot error localized');
 ensureN(str_contains(Notifications::telegramError(400,'Bad Request: message thread not found'),'话题 ID'),'Forum topic error localized');
 echo "PASS: notification encryption, callback auth, input formats, deduplication, queued send, recovery, backoff, retry, rotation and reference safety\n";
}finally{Http::$transport=null;foreach(glob($dir.'/*')?:[] as $p)if(is_file($p))unlink($p);rmdir($dir);}

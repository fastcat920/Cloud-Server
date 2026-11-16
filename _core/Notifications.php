<?php

declare(strict_types=1);
namespace CloudDesk;

final class Notifications
{
    public function __construct(private Store $store, private Security $security) {}
    private static function text(mixed $value,int $max=3800): string
    {
        if (!is_scalar($value)) return '';
        $text=trim((string)$value);
        preg_match('/^.{0,'.$max.'}/us',$text,$match);
        return $match[0] ?? '';
    }
    public function listing(): array
    {
        $s=$this->store->read();
        $channels=array_map(function($row){$row['has_token']=!empty($row['token']);unset($row['token']);return $row;},$s['notification_channels'] ?? []);
        $sources=array_map(function($row){$row['webhook_key']=$this->security->decrypt($row['secret']);unset($row['secret']);return $row;},$s['notification_sources'] ?? []);
        return ['channels'=>$channels,'sources'=>$sources,'deliveries'=>array_reverse($s['notification_jobs'] ?? [])];
    }
    public function saveChannel(array $input): int
    {
        $name=self::text($input['name'] ?? '',80);$chat=self::text($input['chat_id'] ?? '',100);$token=self::text($input['token'] ?? '',200);
        $thread=filter_var($input['thread_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$name || !preg_match('/^(?:-?[0-9]+|@[a-zA-Z0-9_]{5,})$/',$chat)) throw new ApiError('请填写名称和有效的 Chat ID');
        if (($input['thread_id'] ?? '')!=='' && !$thread) throw new ApiError('话题 ID 必须为正整数');
        if ($token && !preg_match('/^[0-9]{5,}:[A-Za-z0-9_-]{20,}$/',$token)) throw new ApiError('Bot Token 格式不正确');
        return $this->store->transaction(function(&$s)use($input,$name,$chat,$token,$thread){
            $id=(int)($input['id'] ?? 0);$old=$id?Store::find($s,'notification_channels',$id):null;
            if (!$token && empty($old['token'])) throw new ApiError('请填写 Bot Token');
            $row=['id'=>$id?:Store::id($s,'notification_channels'),'type'=>'telegram','name'=>$name,'chat_id'=>$chat,'thread_id'=>$thread?:null,'enabled'=>!empty($input['enabled']),'token'=>$token?$this->security->encrypt($token):$old['token']];
            $s['notification_channels']=array_values(array_filter($s['notification_channels'] ?? [],fn($r)=>$r['id']!==$row['id']));$s['notification_channels'][]=$row;
            if (!$row['enabled']) foreach($s['notification_jobs'] ?? [] as $i=>$job) if($job['channel_id']===$row['id'] && in_array($job['status'],['pending','retry'],true))$s['notification_jobs'][$i]['status']='cancelled';
            Store::event($s,'notify.channel.save',$name);return $row['id'];
        });
    }
    public function saveSource(array $input): int
    {
        $name=self::text($input['name'] ?? '',80);$channel=(int)($input['channel_id'] ?? 0);
        if (!$name) throw new ApiError('请填写告警来源名称');
        return $this->store->transaction(function(&$s)use($input,$name,$channel){
            Store::find($s,'notification_channels',$channel);
            $id=(int)($input['id'] ?? 0);$old=$id?Store::find($s,'notification_sources',$id):null;
            $row=['id'=>$id?:Store::id($s,'notification_sources'),'kind'=>'aliyun','name'=>$name,'channel_id'=>$channel,'enabled'=>!empty($input['enabled']),'secret'=>$old['secret'] ?? $this->security->encrypt(bin2hex(random_bytes(32)))];
            $s['notification_sources']=array_values(array_filter($s['notification_sources'] ?? [],fn($r)=>$r['id']!==$row['id']));$s['notification_sources'][]=$row;
            if (!$row['enabled']) foreach($s['notification_jobs'] ?? [] as $i=>$job) if($job['source_id']===$row['id'] && in_array($job['status'],['pending','retry'],true))$s['notification_jobs'][$i]['status']='cancelled';
            Store::event($s,'notify.source.save',$name);return $row['id'];
        });
    }
    public function remove(string $kind,int $id): bool
    {
        return $this->store->transaction(function(&$s)use($kind,$id){
            $table=$kind==='channel'?'notification_channels':'notification_sources';$row=Store::find($s,$table,$id);
            if ($kind==='channel') foreach($s['notification_sources'] ?? [] as $source) if($source['channel_id']===$id)throw new ApiError('渠道仍被告警来源使用，请先修改或删除来源');
            $s[$table]=array_values(array_filter($s[$table],fn($r)=>$r['id']!==$id));
            foreach($s['notification_jobs'] ?? [] as $i=>$job) if(($kind==='channel'?$job['channel_id']:$job['source_id'])===$id && in_array($job['status'],['pending','retry'],true))$s['notification_jobs'][$i]['status']='cancelled';
            Store::event($s,'notify.'.$kind.'.delete',$row['name']);return true;
        });
    }
    public function rotate(int $id): bool
    {
        return $this->store->transaction(function(&$s)use($id){Store::find($s,'notification_sources',$id);foreach($s['notification_sources'] as &$source)if($source['id']===$id)$source['secret']=$this->security->encrypt(bin2hex(random_bytes(32)));unset($source);Store::event($s,'notify.source.rotate',(string)$id);return true;});
    }
    public function authorize(int $sourceId,string $key): array
    {
        foreach($this->store->read()['notification_sources'] ?? [] as $source)if($source['id']===$sourceId && $source['enabled'] && strlen($key)===64 && hash_equals($this->security->decrypt($source['secret']),$key))return $source;
        throw new ApiError('回调地址无效或已停用',401);
    }
    public static function message(array $payload): string
    {
        $event=is_array($payload['data'] ?? null)?array_replace($payload,$payload['data']):$payload;
        // Preserve incoming text verbatim; do not guess that an unknown event is a recovery.
        $parts=[];
        $labels=['subject'=>'告警规则','title'=>'标题','alertName'=>'告警名称','ruleName'=>'告警规则','status'=>'状态','alertState'=>'状态','alertStatus'=>'状态','subtype'=>'事件类型','severity'=>'级别','alertMessage'=>'说明','message'=>'消息','content'=>'内容','text'=>'内容','markdown'=>'内容','time'=>'事件时间','timestamp'=>'时间戳','resource'=>'资源','dimensions'=>'资源维度','instanceName'=>'资源名称','instanceId'=>'资源标识','eventName'=>'事件名称','alertEntityFields'=>'资源信息'];
        $states=['OCCURRED'=>'告警','RESOLVED'=>'恢复','ALARM'=>'告警','OK'=>'恢复','NORMAL_TRIGGER'=>'告警触发','NORMAL_RESOLVE'=>'恢复正常','CRITICAL'=>'严重','WARNING'=>'警告','INFO'=>'提示'];
        foreach(['subject','title','alertName','ruleName','status','alertState','alertStatus','subtype','severity','alertMessage','message','content','text','markdown','time','timestamp','resource','dimensions','instanceName','instanceId','eventName','alertEntityFields'] as $field) {
            $value=$event[$field] ?? null;
            if (in_array($field,['text','markdown'],true) && is_array($value)) $value=$value['content'] ?? $value['text'] ?? $value;
            if (is_array($value)) $value=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $value=self::text($value);
            if ($value!=='') $parts[]=($labels[$field] ?? $field).'：'.($states[$value] ?? $value);
        }
        if (!$parts) $parts[]='事件内容：'.json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return self::text(implode("\n",$parts));
    }
    public function receive(int $sourceId,string $key,array $payload): array
    {
        $source=$this->authorize($sourceId,$key);
        if (!$payload || array_is_list($payload)) throw new ApiError('回调内容必须为非空 JSON 对象或表单');
        $canonical=function($v)use(&$canonical){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);return array_map($canonical,$v);};
        $eventId=$payload['id'] ?? $payload['eventId'] ?? null;
        $phase=$payload['status'] ?? $payload['subtype'] ?? $payload['alertState'] ?? $payload['alertStatus'] ?? '';
        $identity=is_scalar($eventId)?['event'=>(string)$eventId,'phase'=>is_scalar($phase)?(string)$phase:'']:$canonical($payload);
        $hash=hash('sha256',json_encode([$sourceId,$identity],JSON_THROW_ON_ERROR));
        return $this->enqueue($source['channel_id'],$sourceId,$source['name'],"阿里云告警 · ".$source['name']."\n".self::message($payload),$hash,isset($payload['id']) || isset($payload['eventId']) || isset($payload['time']) || isset($payload['timestamp'])?86400:30);
    }
    private function enqueue(int $channelId,int $sourceId,string $sourceName,string $message,string $hash,int $window=30): array
    {
        return $this->store->transaction(function(&$s)use($channelId,$sourceId,$sourceName,$message,$hash,$window){
            $channel=Store::find($s,'notification_channels',$channelId);
            if (!$channel['enabled']) throw new ApiError('通知渠道已停用',409);
            if ($sourceId) { $source=Store::find($s,'notification_sources',$sourceId);if(!$source['enabled'])throw new ApiError('告警来源已停用',409); }
            $jobs=$s['notification_jobs'] ?? [];
            foreach($jobs as $job)if($job['dedupe']===$hash && $job['created_at']>time()-$window)return ['id'=>$job['id'],'duplicate'=>true];
            if(count(array_filter($jobs,fn($r)=>in_array($r['status'],['pending','retry','sending'],true)))>=200)throw new ApiError('通知队列已满，请检查投递记录',503);
            $recent=array_filter($jobs,fn($r)=>$r['created_at']>time()-60);
            if(count($recent)>=200)throw new ApiError('通知请求过于频繁',429);
            $job=['id'=>Store::id($s,'notification_jobs'),'channel_id'=>$channelId,'channel_name'=>$channel['name'],'source_id'=>$sourceId,'source_name'=>$sourceName,'message'=>self::text($message),'dedupe'=>$hash,'created_at'=>time(),'status'=>'pending','attempts'=>0,'next_at'=>time()];
            $s['notification_jobs'][]=$job;$this->trim($s);return ['id'=>$job['id'],'duplicate'=>false];
        });
    }
    private function trim(array &$s): void
    {
        $done=array_filter($s['notification_jobs'] ?? [],fn($r)=>!in_array($r['status'],['pending','retry','sending'],true));
        $keep=array_column(array_slice(array_values($done),-200),'id');
        $s['notification_jobs']=array_values(array_filter($s['notification_jobs'] ?? [],fn($r)=>in_array($r['status'],['pending','retry','sending'],true)||in_array($r['id'],$keep,true)));
    }
    public function test(int $channelId): array
    {
        $job=$this->enqueue($channelId,0,'测试消息','Cloud Desk 消息通知测试：Telegram 渠道连接成功。',bin2hex(random_bytes(32)));
        $this->process(1,$job['id']);
        return $this->job($job['id']);
    }
    private function job(int $id): array { return Store::find($this->store->read(),'notification_jobs',$id); }
    public function retry(int $id): array
    {
        $this->store->transaction(function(&$s)use($id){$job=Store::find($s,'notification_jobs',$id);if(!in_array($job['status'],['failed','retry'],true))throw new ApiError('此消息不需要重试');$channel=Store::find($s,'notification_channels',$job['channel_id']);if(!$channel['enabled'])throw new ApiError('通知渠道已停用，请先启用');if($job['source_id'] && !Store::find($s,'notification_sources',$job['source_id'])['enabled'])throw new ApiError('告警来源已停用，请先启用');foreach($s['notification_jobs'] as &$r)if($r['id']===$id){$r['status']='pending';$r['next_at']=time();$r['attempts']=0;}unset($r);});
        $this->process(1,$id);return $this->job($id);
    }
    public function process(int $limit=10,?int $only=null): int
    {
        $lock=fopen($this->store->directory().'/notification-worker.php','c+');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) {if(is_resource($lock))fclose($lock);return 0;}
        if (fstat($lock)['size']===0)fwrite($lock,Store::GUARD);chmod($this->store->directory().'/notification-worker.php',0600);
        $count=0;
        try {
            while($count<max(1,min(20,$limit))) {
                $job=$this->store->transaction(function(&$s)use($only){
                    $s['notification_jobs'] ??=[];foreach($s['notification_jobs'] as &$r)if(($only===null||$r['id']===$only) && (in_array($r['status'],['pending','retry'],true) && $r['next_at']<=time() || $r['status']==='sending' && ($r['started_at'] ?? 0)<time()-120)) {
                        $r['status']='sending';$r['started_at']=time();$r['attempts']++;$job=$r;unset($r);return $job;
                    }unset($r);return null;
                });
                if (!$job) break;
                $count++;
                $permanent=false;$retryAfter=0;$cancelled=false;
                try {
                    $state=$this->store->read();$channel=Store::find($state,'notification_channels',$job['channel_id']);
                    if(!$channel['enabled']){$cancelled=true;throw new \RuntimeException('通知渠道已停用');}
                    if($channel['type']!=='telegram')throw new \RuntimeException('暂不支持此通知渠道');
                    if($job['source_id'] && !Store::find($state,'notification_sources',$job['source_id'])['enabled']){$cancelled=true;throw new \RuntimeException('告警来源已停用');}
                    $token=$this->security->decrypt($channel['token']);
                    $body=['chat_id'=>$channel['chat_id'],'text'=>$job['message'],'link_preview_options'=>['is_disabled'=>true]];
                    if($channel['thread_id'])$body['message_thread_id']=$channel['thread_id'];
                    try {$response=Http::request('POST','https://api.telegram.org/bot'.$token.'/sendMessage',['Content-Type'=>'application/json'],json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),timeout:4);}
                    catch(\Throwable){throw new \RuntimeException('Telegram 连接失败或超时，请检查服务器网络后重试');}
                    $data=json_decode($response['body'],true);
                    if($response['status']<200 || $response['status']>=300 || !is_array($data)||empty($data['ok'])) { $code=(int)($data['error_code'] ?? $response['status']);$permanent=in_array($code,[400,401,403,404],true);$retryAfter=max(0,min(86400,(int)($data['parameters']['retry_after'] ?? 0)));throw new \RuntimeException($code===429?'Telegram 发送频率受限，请稍后重试':'Telegram 拒绝消息（'.$code.'），请检查 Bot Token、Chat ID 和机器人权限'); }
                    $status='sent';$error='';
                }catch(\Throwable $e){$status=$cancelled || $e->getMessage()==='记录不存在'?'cancelled':(!$permanent && $job['attempts']<3?'retry':'failed');$error=$e instanceof \RuntimeException?$e->getMessage():'消息发送失败';}
                $this->store->transaction(function(&$s)use($job,$status,$error,$retryAfter){foreach($s['notification_jobs'] as &$r)if($r['id']===$job['id'] && $r['dedupe']===$job['dedupe'] && $r['status']==='sending'){$r['status']=$status;$r['error']=$error;$r['updated_at']=time();$r['next_at']=time()+max($retryAfter,$job['attempts']===1?60:300);}unset($r);$this->trim($s);});
                if($only!==null)break;
            }
        } finally {fclose($lock);}
        return $count;
    }
}

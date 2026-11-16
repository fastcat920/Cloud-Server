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
        if (isset($input['format']) && !in_array($input['format'],['readable','original'],true)) throw new ApiError('通知格式无效');
        if (!$name) throw new ApiError('请填写告警来源名称');
        return $this->store->transaction(function(&$s)use($input,$name,$channel){
            Store::find($s,'notification_channels',$channel);
            $id=(int)($input['id'] ?? 0);$old=$id?Store::find($s,'notification_sources',$id):null;
            $row=['id'=>$id?:Store::id($s,'notification_sources'),'kind'=>'aliyun','name'=>$name,'format'=>$input['format'] ?? $old['format'] ?? 'readable','channel_id'=>$channel,'enabled'=>!empty($input['enabled']),'secret'=>$old['secret'] ?? $this->security->encrypt(bin2hex(random_bytes(32)))];
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
    public static function message(array $payload,?string $raw=null): string
    {
        // Known chatbot envelopes carry the already-rendered alert body.
        foreach ([['markdown','text'],['text','content'],['content','text']] as [$field,$child]) {
            $body=is_array($payload[$field] ?? null)?($payload[$field][$child] ?? null):null;
            if (is_string($body) && trim($body)!=='') return $body;
        }
        if (!isset($payload['specversion']) && !isset($payload['resource']) && !isset($payload['status']) && !isset($payload['alertState'])) {
            foreach (['text','content','message'] as $field) if (is_string($payload[$field] ?? null) && trim($payload[$field])!=='') return $payload[$field];
        }
        // Structured events retain every key and value; raw JSON also retains whitespace.
        return $raw ?? json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
    public static function readable(array $payload,?string $raw=null): string
    {
        $content=$payload['domainAlertContent'] ?? null;
        if (is_string($content)) $content=json_decode($content,true);
        if (!is_array($content) || array_is_list($content)) {
            $safe=$payload;unset($safe['webhook'],$safe['userId']);
            return self::message($safe,$safe===$payload?$raw:null);
        }
        $type=is_string($payload['alertType'] ?? null)?$payload['alertType']:'';
        $description=is_scalar($payload['desc'] ?? null)?trim((string)$payload['desc']):'';
        $heading=($type==='addr_alert'?'🔴':'ℹ️').' GTM '.($description!==''?$description:($type==='addr_alert'?'地址异常':'状态变更'));
        $lines=[$heading,''];$used=[];
        foreach (['domainName'=>'域名','addressPoolName'=>'地址池','poolName'=>'地址池','addressName'=>'地址','addressIp'=>'IP','time'=>'时间'] as $key=>$label) {
            if (is_scalar($content[$key] ?? null) && trim((string)$content[$key])!=='') {$lines[]=$label.'：'.(string)$content[$key];$used[]=$key;}
        }
        if (!in_array('time',$used,true) && is_scalar($content['timestamp'] ?? null)) $lines[]='时间戳：'.$content['timestamp'];
        if (is_scalar($content['timestamp'] ?? null)) $used[]='timestamp';
        if (is_scalar($content['instanceName'] ?? null) && trim((string)$content['instanceName'])!=='') {$lines[]='';$lines[]='实例：'.$content['instanceName'];$used[]='instanceName';}
        $extra=$payload;unset($extra['webhook'],$extra['userId'],$extra['domainAlertContent']);
        if (is_scalar($payload['desc'] ?? null)) unset($extra['desc']);
        if ($type==='addr_alert') unset($extra['alertType']);
        if (($payload['title'] ?? '')==='公网全局流量管理 (GTM) 健康检查状态变更通知') unset($extra['title']);
        $remaining=array_diff_key($content,array_flip($used));
        if ($remaining) $extra['domainAlertContent']=$remaining;
        if ($extra) {$lines[]='';$lines[]='其他信息：';$lines[]=json_encode($extra,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);}
        return implode("\n",$lines);
    }
    public static function parts(string $message): array
    {
        $chars=preg_split('//u',$message,-1,PREG_SPLIT_NO_EMPTY);
        if ($chars===false) throw new ApiError('通知内容不是有效的 UTF-8 文本');
        $parts=[];$part='';$units=0;
        foreach ($chars as $char) {
            $size=strlen($char)===4?2:1;
            if ($units+$size>3500) {$parts[]=$part;$part='';$units=0;}
            $part.=$char;$units+=$size;
        }
        if ($part!=='') $parts[]=$part;
        return $parts;
    }
    public function receive(int $sourceId,string $key,array $payload,?string $raw=null): array
    {
        $source=$this->authorize($sourceId,$key);
        if (!$payload || array_is_list($payload)) throw new ApiError('回调内容必须为非空 JSON 对象或表单');
        $canonical=function($v)use(&$canonical){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v);return array_map($canonical,$v);};
        $eventId=$payload['id'] ?? $payload['eventId'] ?? null;
        $phase=$payload['status'] ?? $payload['subtype'] ?? $payload['alertState'] ?? $payload['alertStatus'] ?? $payload['alertType'] ?? '';
        $identity=is_scalar($eventId)?['event'=>(string)$eventId,'phase'=>is_scalar($phase)?(string)$phase:'']:$canonical($payload);
        $format=$source['format'] ?? 'readable';
        $hash=hash('sha256',json_encode([$sourceId,$format==='original'?'original-v1':'gtm-readable-v1',$identity],JSON_THROW_ON_ERROR));
        return $this->enqueue($source['channel_id'],$sourceId,$source['name'],($format==='original'?self::message($payload,$raw):self::readable($payload,$raw)),$hash,isset($payload['id']) || isset($payload['eventId']) || isset($payload['time']) || isset($payload['timestamp']) || isset($payload['domainAlertContent']['timestamp'])?86400:30,$format);
    }
    private function enqueue(int $channelId,int $sourceId,string $sourceName,string $message,string $hash,int $window=30,string $format='original'): array
    {
        if (strlen($message)>262144 || !self::parts($message)) throw new ApiError('通知正文为空或超过 256 KB');
        return $this->store->transaction(function(&$s)use($channelId,$sourceId,$sourceName,$message,$hash,$window,$format){
            $channel=Store::find($s,'notification_channels',$channelId);
            if (!$channel['enabled']) throw new ApiError('通知渠道已停用',409);
            if ($sourceId) { $source=Store::find($s,'notification_sources',$sourceId);if(!$source['enabled'])throw new ApiError('告警来源已停用',409); }
            $jobs=$s['notification_jobs'] ?? [];
            foreach($jobs as $job)if($job['dedupe']===$hash && $job['created_at']>time()-$window)return ['id'=>$job['id'],'duplicate'=>true];
            if(count(array_filter($jobs,fn($r)=>in_array($r['status'],['pending','retry','sending'],true)))>=200)throw new ApiError('通知队列已满，请检查投递记录',503);
            $bytes=strlen($message)+array_sum(array_map(fn($r)=>strlen($r['message']),$jobs));
            foreach($jobs as $i=>$old)if($bytes>8388608 && !in_array($old['status'],['pending','retry','sending'],true)){$bytes-=strlen($old['message']);unset($jobs[$i]);}
            if ($bytes>8388608) throw new ApiError('通知队列容量已满，请先处理待发送消息',503);
            $s['notification_jobs']=array_values($jobs);
            $recent=array_filter($jobs,fn($r)=>$r['created_at']>time()-60);
            if(count($recent)>=200)throw new ApiError('通知请求过于频繁',429);
            $job=['id'=>Store::id($s,'notification_jobs'),'channel_id'=>$channelId,'channel_name'=>$channel['name'],'source_id'=>$sourceId,'source_name'=>$sourceName,'message'=>$message,'content_mode'=>$sourceId?$format:'system','part_index'=>0,'part_attempts'=>0,'part_count'=>count(self::parts($message)),'dedupe'=>$hash,'created_at'=>time(),'status'=>'pending','attempts'=>0,'next_at'=>time()];
            $s['notification_jobs'][]=$job;$this->trim($s);return ['id'=>$job['id'],'duplicate'=>false];
        });
    }
    private function trim(array &$s): void
    {
        $done=array_filter($s['notification_jobs'] ?? [],fn($r)=>!in_array($r['status'],['pending','retry','sending'],true));
        $keep=array_column(array_slice(array_values($done),-200),'id');
        $s['notification_jobs']=array_values(array_filter($s['notification_jobs'] ?? [],fn($r)=>in_array($r['status'],['pending','retry','sending'],true)||in_array($r['id'],$keep,true)));
    }
    public static function telegramError(int $code,string $description=''): string
    {
        $reason=strtolower($description);
        $message=match(true) {
            $code===401 || $code===404=>'Bot Token 无效、已撤销或复制不完整。请从 BotFather 重新复制该机器人的完整 Token（包含冒号），在渠道中替换并保存；发送 /start 或修改 Chat ID 不能解决此错误',
            str_contains($reason,'message thread not found') || str_contains($reason,'thread_id')=>'话题 ID 不正确。私聊请清空话题 ID；群组请填写实际话题 ID',
            str_contains($reason,'bot was blocked')=>'你已屏蔽该机器人，请取消屏蔽，并向这个机器人发送 /start',
            str_contains($reason,"bot can't send messages to bots")=>'Chat ID 填成了机器人。请填写接收消息的用户或群组 Chat ID',
            str_contains($reason,'chat not found')=>'接收目标不存在或机器人尚未加入。请核对 Chat ID，并向这个机器人发送 /start；私聊不能填写机器人用户名或 Token 开头的数字',
            $code===403=>'机器人没有发送权限。请确认已加入目标群组或频道、具备发消息权限，且未被接收者屏蔽',
            $code===429=>'发送频率受限，将按 Telegram 要求等待后重试',
            $code===400=>'消息参数不正确，请核对 Chat ID 和话题 ID',
            default=>'请求失败，请稍后重试或检查服务器到 Telegram 的网络连接',
        };
        return 'Telegram（'.$code.'）：'.$message;
    }
    public function verify(int $channelId): array
    {
        $channel=Store::find($this->store->read(),'notification_channels',$channelId);
        $token=$this->security->decrypt($channel['token']);
        try {$response=Http::request('POST','https://api.telegram.org/bot'.$token.'/getMe',['Content-Type'=>'application/json'],'{}',timeout:4);}
        catch(\Throwable){throw new ApiError('Telegram 连接失败或超时，请检查服务器网络');}
        $data=json_decode($response['body'],true);
        if($response['status']<200 || $response['status']>=300 || !is_array($data) || empty($data['ok'])) throw new ApiError(self::telegramError((int)($data['error_code'] ?? $response['status']),(string)($data['description'] ?? '')));
        $bot=$data['result'] ?? [];
        if(!isset($bot['id'],$bot['username']) || !preg_match('/^[A-Za-z0-9_]+$/',$bot['username']))throw new ApiError('Telegram 返回的机器人信息不完整');
        return ['id'=>$bot['id'],'username'=>$bot['username'],'name'=>$bot['first_name'] ?? $bot['username']];
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
        $this->store->transaction(function(&$s)use($id){$job=Store::find($s,'notification_jobs',$id);if(!in_array($job['status'],['failed','retry'],true))throw new ApiError('此消息不需要重试');$channel=Store::find($s,'notification_channels',$job['channel_id']);if(!$channel['enabled'])throw new ApiError('通知渠道已停用，请先启用');if($job['source_id'] && !Store::find($s,'notification_sources',$job['source_id'])['enabled'])throw new ApiError('告警来源已停用，请先启用');foreach($s['notification_jobs'] as &$r)if($r['id']===$id){$r['status']='pending';$r['next_at']=time();$r['attempts']=0;$r['part_attempts']=0;}unset($r);});
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
                        $r['status']='sending';$r['started_at']=time();$r['attempts']++;$r['part_attempts']=($r['part_attempts'] ?? 0)+1;$job=$r;unset($r);return $job;
                    }unset($r);return null;
                });
                if (!$job) break;
                $count++;
                $permanent=false;$retryAfter=0;$cancelled=false;$nextPart=(int)($job['part_index'] ?? 0);$delivered=false;
                try {
                    $state=$this->store->read();$channel=Store::find($state,'notification_channels',$job['channel_id']);
                    if(!$channel['enabled']){$cancelled=true;throw new \RuntimeException('通知渠道已停用');}
                    if($channel['type']!=='telegram')throw new \RuntimeException('暂不支持此通知渠道');
                    if($job['source_id'] && !Store::find($state,'notification_sources',$job['source_id'])['enabled']){$cancelled=true;throw new \RuntimeException('告警来源已停用');}
                    $token=$this->security->decrypt($channel['token']);
                    $parts=self::parts($job['message']);
                    $body=['chat_id'=>$channel['chat_id'],'text'=>$parts[$nextPart],'link_preview_options'=>['is_disabled'=>true]];
                    if($channel['thread_id'])$body['message_thread_id']=$channel['thread_id'];
                    try {$response=Http::request('POST','https://api.telegram.org/bot'.$token.'/sendMessage',['Content-Type'=>'application/json'],json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),timeout:4);}
                    catch(\Throwable){throw new \RuntimeException('Telegram 连接失败或超时，请检查服务器网络后重试');}
                    $data=json_decode($response['body'],true);
                    if($response['status']<200 || $response['status']>=300 || !is_array($data)||empty($data['ok'])) { $code=(int)($data['error_code'] ?? $response['status']);$permanent=in_array($code,[400,401,403,404],true);$retryAfter=max(0,min(86400,(int)($data['parameters']['retry_after'] ?? 0)));throw new \RuntimeException(self::telegramError($code,(string)($data['description'] ?? ''))); }
                    $delivered=true;$nextPart++;$status=$nextPart<count($parts)?'pending':'sent';$error='';
                }catch(\Throwable $e){$status=$cancelled || $e->getMessage()==='记录不存在'?'cancelled':(!$permanent && ($job['part_attempts'] ?? $job['attempts'])<3?'retry':'failed');$error=$e instanceof \RuntimeException?$e->getMessage():'消息发送失败';}
                $this->store->transaction(function(&$s)use($job,$status,$error,$retryAfter,$nextPart,$delivered){foreach($s['notification_jobs'] as &$r)if($r['id']===$job['id'] && $r['dedupe']===$job['dedupe'] && $r['status']==='sending'){$r['status']=$status;$r['error']=$error;$r['updated_at']=time();$r['part_index']=$nextPart;if($delivered)$r['part_attempts']=0;$r['next_at']=$status==='pending'?time():time()+max($retryAfter,($job['part_attempts'] ?? $job['attempts'])===1?60:300);}unset($r);$this->trim($s);});
                if($only!==null && $status!=='pending')break;
            }
        } finally {fclose($lock);}
        return $count;
    }
}

<?php

declare(strict_types=1);
namespace CloudDesk;

final class Releases
{
    public function __construct(private Store $store, private Security $security, private Objects $objects, private Configs $configs) {}
    public function lock(): mixed
    {
        $path = $this->store->directory().'/publish-lock.php';
        $handle = fopen($path, 'c+');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            throw new ApiError('已有发布或恢复操作进行中，请稍后重试', 409);
        }
        if (fstat($handle)['size'] === 0) fwrite($handle, Store::GUARD);
        chmod($path, 0600);
        return $handle;
    }
    public function recover(): void
    {
        $state = $this->store->read();
        if (!array_filter($state['publications'],fn ($job) => $job['status'] === 'publishing') && !array_filter($state['versions'],fn ($version) => $version['status'] === 'publishing')) return;
        try { $lock = $this->lock(); } catch (ApiError) { return; }
        try { $this->recoverLocked(); } finally { fclose($lock); }
    }
    private function recoverLocked(): void
    {
        $this->store->transaction(function (array &$s) {
            foreach ($s['publications'] as &$job) {
                if ($job['status'] === 'publishing') {
                    $job['status'] = 'failed';
                    $job['error'] = '上次发布请求已中断，可使用冻结内容重试';
                    $job['finished_at'] = time();
                    foreach ($s['versions'] as &$version) {
                        if ($version['id'] === $job['version_id'] && $version['status'] === 'publishing') $version['status'] = 'partial';
                    }
                    unset($version);
                }
            }
            unset($job);
            foreach ($s['versions'] as &$version) {
                if ($version['status'] !== 'publishing') continue;
                $latest=[];
                foreach ($s['publications'] as $job) if ($job['version_id'] === $version['id']) $latest[$job['target_id']]=$job;
                $version['status']=$latest && !array_filter($latest,fn ($job)=>$job['status']!=='success') ? 'published' : 'partial';
                if ($version['status']==='published') {
                    $version['published_at']=time();
                    foreach ($s['versions'] as &$old) if ($old['id'] !== $version['id'] && ($old['plan_id'] ?? 1) === ($version['plan_id'] ?? 1) && $old['status'] === 'published') $old['status']='archived';
                    unset($old);
                }
            }
            unset($version);
        });
    }
    public function run(int $id, array $targets, ?int $retry = null): array
    {
        $lock = $this->lock();
        try {
            $this->recoverLocked();
            if ($retry !== null) {
                $state = $this->store->read();
                $previous = Store::find($state, 'publications', $retry);
                foreach ($state['publications'] as $newer) {
                    if ($newer['id'] > $previous['id'] && $newer['status'] === 'success' && (($newer['destination'] ?? null) === ($previous['destination'] ?? null) && isset($previous['destination']) || $newer['target_id'] === $previous['target_id'])) throw new ApiError('该地址已有更新的成功发布，请重新选择需要的修订发布',409);
                }
                if (empty($previous['frozen'])) throw new ApiError('旧发布记录没有冻结快照，请重新发布修订');
                $frozen = json_decode($this->security->decrypt($previous['frozen']), true, 512, JSON_THROW_ON_ERROR);
                $current = Store::find($state, 'targets', $previous['target_id']);
                foreach (['account_id','region','bucket','key','public_read'] as $field) {
                    if (($current[$field] ?? null) !== ($frozen['target'][$field] ?? null)) throw new ApiError('目标设置已变化，不能重试旧任务，请重新发布', 409);
                }
                if (!$current['enabled'] || !Store::find($state, 'accounts', $current['account_id'])['enabled']) throw new ApiError('发布目标或账号已停用');
                $version = Store::find($state,'versions',$previous['version_id']);
                $plan = Store::find($state,'plans',$version['plan_id']);
                if (!in_array($previous['target_id'],$plan['targets'],true)) throw new ApiError('Target is no longer bound to this plan',409);
                $id = $previous['version_id'];
                $jobs = $this->store->transaction(function (array &$s) use ($previous) {
                    $job = $previous; $job['status'] = 'publishing'; $job['error'] = ''; $job['started_at'] = time();
                    unset($job['finished_at']);
                    Store::put($s, 'publications', $job);
                    $version = Store::find($s, 'versions', $job['version_id']); $version['status'] = 'publishing'; Store::put($s, 'versions', $version);
                    return [$job];
                });
            } else {
                if (!$targets) throw new ApiError('请选择发布目标');
                $jobs = $this->store->transaction(function (array &$s) use ($id, $targets) {
                    $version = Store::find($s, 'versions', $id);
                    $plan = Store::find($s, 'plans', $version['plan_id'] ?? 1);
                    if (array_diff($targets, $plan['targets'])) throw new ApiError('发布目标未绑定当前方案');
                    Configs::preflight($version);
                    $payload = $this->configs->encode($version, $s['settings']);
                    $jobs = [];
                    foreach ($targets as $targetId) {
                        $target = Store::find($s, 'targets', $targetId);
                        $account = Store::find($s, 'accounts', $target['account_id']);
                        if (!$target['enabled'] || !$account['enabled']) throw new ApiError('发布目标或账号已停用');
                        foreach ($s['plans'] as $other) {
                            if ($other['id'] === $plan['id']) continue;
                            foreach ($other['targets'] as $otherId) {
                                $candidate = Store::find($s, 'targets', $otherId);
                                if ([$candidate['region'],$candidate['bucket'],$candidate['key']] === [$target['region'],$target['bucket'],$target['key']] && Store::find($s, 'accounts', $candidate['account_id'])['provider'] === $account['provider']) throw new ApiError('发布地址与其他方案冲突');
                            }
                        }
                        $jobId = Store::id($s, 'publications');
                        $filename = basename($target['key']); $directory = dirname($target['key']);
                        $dot = strrpos($filename, '.');
                        $suffix = '.release-'.$jobId.'-'.substr(hash('sha256', $payload), 0, 12);
                        $filename = $dot === false ? $filename.$suffix : substr($filename,0,$dot).$suffix.substr($filename,$dot);
                        $versionKey = ($directory === '.' ? '' : $directory.'/').$filename;
                        $frozen = ['payload'=>$payload,'target'=>$target,'account'=>$account,'version_key'=>$versionKey];
                        $job = ['id'=>$jobId,'version_id'=>$id,'target_id'=>$targetId,'target_name'=>$target['name'],'status'=>'publishing','stage'=>'queued','checksum'=>hash('sha256',$payload),'started_at'=>time(),'error'=>'','destination'=>hash('sha256',json_encode([$account['provider'],$target['region'],$target['bucket'],$target['key']])), 'frozen'=>$this->security->encrypt(json_encode($frozen, JSON_THROW_ON_ERROR))];
                        $s['publications'][] = $job; $jobs[] = $job;
                    }
                    $version['status'] = 'publishing'; Store::put($s,'versions',$version);
                    $s['publications'] = array_slice($s['publications'], -1000);
                    return $jobs;
                });
            }
            foreach ($jobs as &$job) {
                try {
                    $frozen = json_decode($this->security->decrypt($job['frozen']), true, 512, JSON_THROW_ON_ERROR);
                    $target = $frozen['target']; $account = $frozen['account']; $payload = $frozen['payload'];
                    foreach (['version_upload','fixed_upload','verify'] as $stage) {
                        $job['stage'] = $stage;
                        $this->store->transaction(fn (array &$s) => Store::put($s,'publications',$job));
                        if ($stage === 'verify') {
                            $check = $this->objects->request($account,'GET',$target['region'],$target['bucket'],$target['key']);
                            if (!hash_equals($job['checksum'],hash('sha256',$check['body']))) throw new ApiError('发布后读取校验失败');
                        } else {
                            $this->objects->request($account,'PUT',$target['region'],$target['bucket'],$stage === 'version_upload' ? $frozen['version_key'] : $target['key'],body:$payload,publicRead:$target['public_read']);
                        }
                    }
                    $job['status'] = 'success'; $job['stage'] = 'done';
                } catch (\Throwable $e) {
                    $job['status'] = 'failed'; $job['error'] = $e instanceof \RuntimeException ? $e->getMessage() : '发布失败';
                }
                $job['finished_at'] = time();
                $this->store->transaction(function (array &$s) use ($job) { Store::put($s,'publications',$job); Store::event($s,'config.publish',$job['target_name'],$job['status']); });
            }
            unset($job);
            $this->store->transaction(function (array &$s) use ($id) {
                $version = Store::find($s,'versions',$id); $latest = [];
                foreach ($s['publications'] as $job) if ($job['version_id'] === $id) $latest[$job['target_id']] = $job;
                $version['status'] = $latest && !array_filter($latest,fn ($job) => $job['status'] !== 'success') ? 'published' : 'partial';
                if ($version['status'] === 'published') {
                    foreach ($s['versions'] as &$old) if ($old['id'] !== $id && $old['plan_id'] === $version['plan_id'] && $old['status'] === 'published') $old['status'] = 'archived';
                    unset($old); $version['published_at'] = time();
                }
                Store::put($s,'versions',$version);
            });
            foreach ($jobs as &$job) unset($job['frozen']);
            return $jobs;
        } finally { fclose($lock); }
    }
}

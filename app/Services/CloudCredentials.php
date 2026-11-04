<?php
namespace App\Services;
use App\Models\{CloudAccount,AliyunDnsAccount,ClientStorageTarget};
use App\Support\FileStore\Store;
use Illuminate\Support\Facades\Crypt;
class CloudCredentials
{
    const PROVIDERS = ['aliyun_oss'=>'aliyun','tencent_cos'=>'tencent','ucloud_us3'=>'ucloud'];
    public function account($id, $provider, $enabled = true) {
        $account = CloudAccount::findOrFail($id);
        if ($account->provider !== $provider) abort(422,'账号厂商与当前功能不匹配');
        if ($enabled && !$account->enabled) abort(422,'关联云账号已停用');
        return $account;
    }
    public function references($id) {
        return array_merge(ClientStorageTarget::where('cloud_account_id',$id)->get()->map(fn($r)=>'存储目标：'.$r->name)->all(),AliyunDnsAccount::where('cloud_account_id',$id)->get()->map(fn($r)=>'云解析：'.$r->name)->all());
    }
    public function migrate() {
        Store::transaction(function () {
            foreach ([ClientStorageTarget::class,AliyunDnsAccount::class] as $class) foreach ($class::get() as $row) {
                if ($row->cloud_account_id) continue;
                $raw=$row->getAttributes();
                $key=$raw['access_key_id_encrypted']??null;
                $secret=$raw[$class===ClientStorageTarget::class?'secret_key_encrypted':'access_key_secret_encrypted']??null;
                if (!$key || !$secret) continue;
                try { $id=Crypt::decryptString($key); $password=Crypt::decryptString($secret); $token=empty($raw['security_token_encrypted'])?'':Crypt::decryptString($raw['security_token_encrypted']); } catch (\Throwable $e) { continue; }
                $provider=$class===ClientStorageTarget::class?self::PROVIDERS[$row->provider]:'aliyun';
                $match=null;
                foreach (CloudAccount::where('provider',$provider)->get() as $account) {
                    try { if (Crypt::decryptString($account->access_key_id_encrypted)===$id && Crypt::decryptString($account->access_key_secret_encrypted)===$password && ($account->security_token_encrypted?Crypt::decryptString($account->security_token_encrypted):'')===$token) { $match=$account;break; } } catch (\Throwable $e) {}
                }
                if (!$match) $match=CloudAccount::create(['name'=>$row->name.'（已迁移）','provider'=>$provider,'site'=>'china','enabled'=>true,'access_key_id_encrypted'=>$key,'access_key_secret_encrypted'=>$secret,'security_token_encrypted'=>$raw['security_token_encrypted']??null]);
                $row->cloud_account_id=$match->id;
                $row->access_key_id_encrypted=null;
                $row->{$class===ClientStorageTarget::class?'secret_key_encrypted':'access_key_secret_encrypted'}=null;
                $row->security_token_encrypted=null;$row->save();
            }
        });
    }
}

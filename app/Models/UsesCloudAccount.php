<?php
namespace App\Models;
trait UsesCloudAccount
{
    public function assertCloudAccountEnabled() {
        if ($this->cloud_account_id) app(\App\Services\CloudCredentials::class)->account($this->cloud_account_id,$this instanceof ClientStorageTarget ? \App\Services\CloudCredentials::PROVIDERS[$this->provider] : 'aliyun');
    }
    private function sharedCredential($field,$fallback) {
        if (!$this->cloud_account_id) return $fallback;
        $account=app(\App\Services\CloudCredentials::class)->account($this->cloud_account_id,$this instanceof ClientStorageTarget ? \App\Services\CloudCredentials::PROVIDERS[$this->provider] : 'aliyun',false);
        return $account->$field;
    }
    public function getAccessKeyIdEncryptedAttribute($value) { return $this->sharedCredential('access_key_id_encrypted',$value); }
    public function getAccessKeySecretEncryptedAttribute($value) { return $this->sharedCredential('access_key_secret_encrypted',$value); }
    public function getSecretKeyEncryptedAttribute($value) { return $this->sharedCredential('access_key_secret_encrypted',$value); }
    public function getSecurityTokenEncryptedAttribute($value) { return $this->sharedCredential('security_token_encrypted',$value); }
}

<?php

declare(strict_types=1);

namespace CloudDesk;

final class Security
{
    private string $key;
    public function __construct(Store|string $store)
    {
        if (is_string($store)) {
            $this->key = base64_decode($store, true) ?: '';
            if (strlen($this->key) !== 32) {
                throw new \RuntimeException('备份密钥无效');
            }
            return;
        }
        $path = $store->directory().'/key.php';
        $store->transaction(function (array &$state) use ($path) {
            if (!is_file($path)) {
                if (isset($state['admin']) || count($state['accounts'])) {
                    throw new \RuntimeException('加密密钥丢失，请从备份恢复 key.php');
                }
                $key = base64_encode(random_bytes(32));
                $handle = fopen($path, 'x');
                if (!$handle) {
                    throw new \RuntimeException('无法生成密钥');
                }chmod($path, 0600);
                fwrite($handle, "<?php return '".$key."';\n");
                fclose($handle);
            }
        }, false);
        $encoded = require $path;
        $this->key = base64_decode($encoded, true);
        if (strlen($this->key) !== 32) {
            throw new \RuntimeException('加密密钥无效');
        }
    }
    public function encrypt(string $value): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('加密失败');
        }
        return base64_encode($iv.$tag.$cipher);
    }
    public function decrypt(?string $value): string
    {
        if (!$value) {
            return '';
        }
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('密钥数据无效');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new \RuntimeException('凭证解密失败，请恢复正确的 key.php');
        }
        return $plain;
    }
    public static function legacyDecrypt(?string $value, string $key): string
    {
        if (!$value) {
            return '';
        }
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }
        $data = json_decode(base64_decode($value, true) ?: '', true);
        if (!is_array($data) || !isset($data['iv'],$data['value'],$data['mac'])) {
            throw new \RuntimeException('旧凭证格式错误');
        }
        if (!hash_equals(hash_hmac('sha256', $data['iv'].$data['value'], $key), $data['mac'])) {
            throw new \RuntimeException('旧 APP_KEY 不正确');
        }
        $plain = openssl_decrypt($data['value'], 'aes-256-cbc', $key, 0, base64_decode($data['iv'], true));
        if ($plain === false) {
            throw new \RuntimeException('旧凭证解密失败');
        }
        return $plain;
    }
}
final class Sessions implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private string $dir;
    private $handle = null;
    public function __construct(string $directory)
    {
        $this->dir = $directory.'/sessions';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0700, true);
        }
    }
    private function path(string $id): string
    {
        if (!preg_match('/^[a-zA-Z0-9,-]{16,128}$/', $id)) {
            throw new \RuntimeException('会话无效');
        }
        return $this->dir.'/'.$id.'.php';
    }
    public function open(string $path, string $name): bool
    {
        return true;
    }
    public function close(): bool
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
        $this->handle = null;
        return true;
    }
    public function read(string $id): string|false
    {
        $path = $this->path($id);
        $this->handle = fopen($path, 'c+');
        flock($this->handle, LOCK_EX);
        chmod($path, 0600);
        $raw = stream_get_contents($this->handle);
        return str_starts_with($raw, Store::GUARD) ? substr($raw, strlen(Store::GUARD)) : '';
    }
    public function write(string $id, string $data): bool
    {
        if (!is_resource($this->handle)) {
            $this->read($id);
        }rewind($this->handle);
        ftruncate($this->handle, 0);
        return fwrite($this->handle, Store::GUARD.$data) !== false;
    }
    public function destroy(string $id): bool
    {
        $this->close();
        $path = $this->path($id);
        if (is_file($path)) {
            unlink($path);
        }
        return true;
    }
    public function gc(int $max_lifetime): int|false
    {
        $count = 0;
        foreach (glob($this->dir.'/*.php') ?: [] as $path) {
            if (filemtime($path) < time() - $max_lifetime) {
                if (unlink($path)) {
                    $count++;
                }
            }
        }
        return $count;
    }
    public function validateId(string $id): bool
    {
        return is_file($this->path($id)) && filemtime($this->path($id)) > time() - 43200;
    }
    public function updateTimestamp(string $id, string $data): bool
    {
        return touch($this->path($id));
    }
}

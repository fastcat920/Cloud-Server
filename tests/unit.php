<?php

declare(strict_types=1);
foreach (['Store','Security','Cloud','Objects','Configs','Importer'] as $file) {
    require __DIR__.'/../_core/'.$file.'.php';
}
use CloudDesk\{Store,Security,Cloud,Http,Objects,Configs,Importer};

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
$directory = sys_get_temp_dir().'/cloud-desk-unit-'.bin2hex(random_bytes(8));
$store = new Store($directory);
$security = new Security($store);
try {
    $store->transaction(function (array &$s) {
        $s['accounts'][] = ['id' => Store::id($s, 'accounts'),'name' => 'one'];
    });
    try {
        $store->transaction(function (array &$s) {
            $s['accounts'][] = ['id' => Store::id($s, 'accounts')];
            throw new RuntimeException('abort');
        });
    } catch (RuntimeException $expected) {
    }
    verify(count($store->read()['accounts']) === 1, 'Rollback failed');
    if (function_exists('pcntl_fork')) {
        $children = [];
        for ($n = 0;$n < 4;$n++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                for ($i = 0;$i < 20;$i++) {
                    $store->transaction(function (array &$s) {
                        Store::event($s, 'test', 'concurrent');
                    });
                }exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            verify(pcntl_wexitstatus($status) === 0, 'Child failed');
        }verify(count($store->read()['events']) === 80, 'Concurrent writes lost data');
    }
    $cipher = $security->encrypt('private-value');
    verify(!str_contains($cipher, 'private-value') && $security->decrypt($cipher) === 'private-value', 'Encryption round trip');
    try {
        $security->decrypt(substr($cipher, 0, -4).'AAAA');
        throw new LogicException('Tampering accepted');
    } catch (RuntimeException $expected) {
    }
    $account = ['id' => 1,'name' => 'test','provider' => 'aliyun','enabled' => true,'site' => 'china','credentials' => ['key' => $security->encrypt('test-id'),'secret' => $security->encrypt('test-secret'),'token' => $security->encrypt('temporary')]];
    $cloud = new Cloud($security);
    $objects = new Objects($cloud);
    $requests = [];
    Http::$transport = function ($method, $url, $headers, $body) use (&$requests) {
        $requests[] = compact('method', 'url', 'headers', 'body');
        if (str_contains($url, 'ecs.aliyuncs.com')) {
            return ['status' => 200,'body' => json_encode(['TotalCount' => 1,'Instances' => ['Instance' => [['InstanceId' => 'i-test','InstanceName' => 'Test','Status' => 'Running','Cpu' => 2,'Memory' => 4096]]]])];
        }
        if (str_contains($url, 'tencentcloudapi.com')) {
            return ['status' => 200,'body' => json_encode(['Response' => ['TotalCount' => 1,'InstanceSet' => [['InstanceId' => 'ins-test','InstanceState' => 'RUNNING','CPU' => 2,'Memory' => 4]]]])];
        }
        if (str_contains($url, 'api.ucloud')) {
            return ['status' => 200,'body' => json_encode(['RetCode' => 0,'TotalCount' => 1,'UHostSet' => [['UHostId' => 'uhost-test','State' => 'Running','CPU' => 2,'Memory' => 4096]]])];
        }
        return ['status' => 200,'body' => '<ListBucketResult><CommonPrefixes><Prefix>目录/</Prefix></CommonPrefixes><Contents><Key>a.txt</Key><Size>5</Size></Contents><NextMarker>a.txt</NextMarker></ListBucketResult>'];
    };
    foreach (['aliyun','tencent','ucloud'] as $provider) {
        $account['provider'] = $provider;
        $result = $cloud->instances($account, 'test-region', 2, 'org-test');
        verify($result['items'][0]['memory'] === 4 || $result['items'][0]['memory'] === 4.0, 'Instance normalization');
        $cloud->instanceAction($account, 'test-region', $result['items'][0]['id'], 'start');
        $list = $objects->list($account, 'test-region', 'test-bucket');
        verify($list['next'] === 'a.txt' && $list['items'][0]['directory'], 'Object XML parsing');
        $objects->request($account, 'PUT', 'test-region', 'test-bucket', '目录/a.txt', body:'payload');
        $last = end($requests);
        verify($last['body'] === 'payload' && !str_contains($last['url'], 'test-secret'), 'Object upload');
        verify(str_contains($last['headers']['Authorization'], match($provider) {
            'aliyun' => 'OSS4-HMAC-SHA256','tencent' => 'q-sign-algorithm=sha1',default => 'AWS4-HMAC-SHA256'
        }), 'Provider signature');
    }
    Http::$transport = function ($method, $url, $headers, $body) {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        $action = $query['Action'] ?? $headers['X-TC-Action'] ?? '';
        if ($action === 'QueryAccountBalance') {
            $data = ['Data' => ['AvailableAmount' => '1,234.50','Currency' => 'CNY']];
        } elseif ($action === 'QueryBillOverview') {
            $data = ['Data' => ['Items' => ['Item' => [['PretaxAmount' => '12.30']]]]];
        } elseif ($action === 'DescribeAccountBalance') {
            $data = ['Response' => ['RealBalance' => 123450]];
        } elseif ($action === 'DescribeBillSummary') {
            $data = ['Response' => ['Ready' => 1,'SummaryDetail' => [['RealTotalCost' => '12.30']]]];
        } elseif ($action === 'GetBalance') {
            $data = ['RetCode' => 0,'AccountInfo' => ['AmountAvailable' => 1234.5]];
        } else {
            $data = ['RetCode' => 0,'TotalPaidAmount' => 10,'TotalUnpaidAmount' => 2.3];
        }
        return ['status' => 200,'body' => json_encode($data)];
    };
    foreach (['aliyun','tencent','ucloud'] as $provider) {
        $account['provider'] = $provider;
        $billing = $cloud->billing($account);
        verify(abs($billing['balance'] - 1234.5) < 0.001 && abs($billing['current'] - 12.3) < 0.001, 'Billing units and summary fields '.$provider);
    }
    $account['provider'] = 'cloudflare';
    Http::$transport = fn () => ['status' => 200,'body' => '{"success":false,"errors":[{"message":"Permission denied"}]}'];
    try {
        $cloud->cloudflare($account, 'GET', '/zones');
        throw new LogicException('Cloudflare error ignored');
    } catch (RuntimeException $expected) {
        verify($expected->getMessage() === 'Permission denied', 'Cloudflare error normalization');
    }
    $configs = new Configs($security);
    $version = ['mode' => 'plain','content' => ['z' => 1,'a' => 2]];
    $plain = $configs->encode($version, []);
    verify(strpos($plain, '"a"') < strpos($plain, '"z"'), 'Canonical config');
    $version['mode'] = 'xor_base64';
    $settings = ['xor_key' => $security->encrypt('key')];
    $payload = $configs->encode($version, $settings);
    $raw = base64_decode($payload);
    $decoded = '';
    for ($i = 0;$i < strlen($raw);$i++) {
        $decoded .= $raw[$i] ^ 'key'[$i % 3];
    }verify($decoded === $plain, 'Legacy XOR compatibility');
    if (function_exists('sodium_crypto_sign_keypair')) {
        $pair = sodium_crypto_sign_keypair();
        $settings['signing_secret'] = $security->encrypt(base64_encode(sodium_crypto_sign_secretkey($pair)));
        $version['mode'] = 'signed_xor_v2';
        $encoded = json_decode($configs->encode($version, $settings), true);
        verify($encoded['_format'] === 'fastcat-config-v2' && sodium_crypto_sign_verify_detached(base64_decode($encoded['signature']), $encoded['payload'], sodium_crypto_sign_publickey($pair)), 'Signed config compatibility');
    }
    // Test importing Laravel's encrypted strings without loading Laravel.
    $legacyKey = random_bytes(32);
    $legacyEncrypt = function ($value) use ($legacyKey) {
        $iv = random_bytes(16);
        $value = openssl_encrypt($value, 'aes-256-cbc', $legacyKey, 0, $iv);
        $iv = base64_encode($iv);
        return base64_encode(json_encode(['iv' => $iv,'value' => $value,'mac' => hash_hmac('sha256', $iv.$value, $legacyKey),'tag' => '']));
    };
    $old = ['v2_cloud_account' => ['rows' => [['id' => 1,'name' => 'Old','provider' => 'aliyun','enabled' => true,'access_key_id_encrypted' => $legacyEncrypt('old-id'),'access_key_secret_encrypted' => $legacyEncrypt('old-secret')]]],'v2_client_config' => ['rows' => [['id' => 5,'config_version' => 7,'content_json' => '{"domains":[]}','encryption_mode' => 'plain','status' => 'draft']]],'v2_client_storage_target' => ['rows' => [['id' => 1,'cloud_account_id' => 1,'provider' => 'aliyun_oss','name' => 'old target','bucket' => 'old-bucket','object_key' => 'config.json','endpoint' => 'oss-cn-hangzhou.aliyuncs.com']]]];
    $store->transaction(function (array &$s) {
        $s['accounts'] = [];
    });
    $result = (new Importer($security))->run($store, $old, 'base64:'.base64_encode($legacyKey));
    verify($result['accounts'] === 1 && $result['targets'] === 1, 'Import counts');
    $imported = $store->read();
    verify($security->decrypt($imported['accounts'][0]['credentials']['secret']) === 'old-secret', 'Import re-encryption');
    verify($imported['versions'][0]['version'] === 7, 'Import preserves versions');
    $path = $directory.'/state.php';
    file_put_contents($path, Store::GUARD.'{broken');
    try {
        $store->transaction(fn (array &$s) => $s['x'] = 1);
        throw new LogicException('Corrupt data accepted');
    } catch (JsonException $expected) {
    }verify(file_get_contents($path) === Store::GUARD.'{broken', 'Corrupt data overwritten');
    echo "PASS: atomic store, concurrency, encryption, 3-provider compute/object signing, Cloudflare errors, config compatibility, v1 import, corruption protection\n";
} finally {
    Http::$transport = null;
    foreach (glob($directory.'/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }rmdir($directory);
}

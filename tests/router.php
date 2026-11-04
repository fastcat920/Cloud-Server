<?php

// Test-only transport. Never active under PHP-FPM or without the explicit test flag.
if (PHP_SAPI !== 'cli-server' || getenv('CLOUD_DESK_TEST') !== '1') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && $path !== '/index.php' && is_file($root.$path)) {
    return false;
}
foreach (['Store','Security','Cloud','Objects','Configs','Importer','Api'] as $file) {
    require_once $root.'/_core/'.$file.'.php';
}
CloudDesk\Http::$transport = function ($method, $url, $headers, $body) {
    $host = parse_url($url, PHP_URL_HOST);
    $path = parse_url($url, PHP_URL_PATH);
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
    if (str_contains($host, 'ecs.aliyuncs.com')) {
        return ['status' => 200,'body' => json_encode(['RequestId' => 'test','TotalCount' => 1,'Instances' => ['Instance' => [['InstanceId' => 'i-native-test','InstanceName' => 'Native test','Status' => 'Running','Cpu' => 2,'Memory' => 4096]]]])];
    }
    if (str_contains($host, 'tencentcloudapi.com')) {
        return ['status' => 200,'body' => json_encode(['Response' => ['RequestId' => 'test','TotalCount' => 1,'InstanceSet' => [['InstanceId' => 'ins-native-test','InstanceState' => 'RUNNING','CPU' => 2,'Memory' => 4]]]])];
    }
    if (str_contains($host, 'api.ucloud')) {
        return ['status' => 200,'body' => json_encode(['RetCode' => 0,'TotalCount' => 1,'UHostSet' => [['UHostId' => 'uhost-native-test','State' => 'Running','CPU' => 2,'Memory' => 4096]]])];
    }
    if (str_contains($host, 'alidns')) {
        return ['status' => 200,'body' => json_encode(['RequestId' => 'test','TotalCount' => 1,'DomainRecords' => ['Record' => [['RecordId' => 'test-record','RR' => 'www','Type' => 'A','Value' => '192.0.2.10','TTL' => 600]]]])];
    }
    if (str_contains($host, 'cloudflare.com')) {
        return ['status' => 200,'body' => '{"success":true,"result":[{"id":"test-zone","name":"example.com","status":"active"}],"result_info":{"total_count":1}}'];
    }
    $file = getenv('CLOUD_DESK_DATA').'/mock-'.hash('sha256', $host.$path).'.php';
    if ($method === 'PUT') {
        file_put_contents($file, CloudDesk\Store::GUARD.$body);
        return ['status' => 200,'body' => ''];
    }
    if ($method === 'DELETE') {
        if (is_file($file)) {
            unlink($file);
        }
        return ['status' => 204,'body' => ''];
    }
    if ($method === 'GET' && is_file($file)) {
        return ['status' => 200,'body' => substr(file_get_contents($file), strlen(CloudDesk\Store::GUARD))];
    }
    if ($method === 'GET' && count($query) && !str_starts_with($host, 'oss-')) {
        return ['status' => 200,'body' => '<ListBucketResult><Contents><Key>demo.txt</Key><Size>5</Size></Contents></ListBucketResult>'];
    }
    return ['status' => 200,'body' => '<ListAllMyBucketsResult><Buckets><Bucket><Name>test-bucket</Name><Location>cn-hangzhou</Location></Bucket></Buckets></ListAllMyBucketsResult>'];
};
require $root.'/index.php';

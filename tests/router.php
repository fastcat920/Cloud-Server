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
foreach (['Store','Security','Cloud','Objects','Configs','Plans','Checks','Releases','Gtm','Importer','Notifications','Api'] as $file) {
    require_once $root.'/_core/'.$file.'.php';
}
CloudDesk\Http::$transport = function ($method, $url, $headers, $body) {
    $host = parse_url($url, PHP_URL_HOST);
    $path = parse_url($url, PHP_URL_PATH);
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
    if ($method==='POST' && $body!=='' && ($headers['Content-Type'] ?? '')==='application/x-www-form-urlencoded') parse_str($body,$query);
    if ($host==='api.telegram.org') return ['status'=>200,'body'=>'{"ok":true,"result":{"message_id":1}}'];
    if (str_contains($host, 'ecs.aliyuncs.com')) {
        return ['status' => 200,'body' => json_encode(['RequestId' => 'test','TotalCount' => 1,'Instances' => ['Instance' => [['InstanceId' => 'i-native-test','InstanceName' => 'Native test','Status' => 'Running','Cpu' => 2,'Memory' => 4096]]]])];
    }
    if (str_contains($host, 'tencentcloudapi.com')) {
        return ['status' => 200,'body' => json_encode(['Response' => ['RequestId' => 'test','TotalCount' => 1,'InstanceSet' => [['InstanceId' => 'ins-native-test','InstanceState' => 'RUNNING','CPU' => 2,'Memory' => 4]]]])];
    }
    if (str_contains($host, 'api.ucloud')) {
        return ['status' => 200,'body' => json_encode(['RetCode' => 0,'TotalCount' => 1,'UHostSet' => [['UHostId' => 'uhost-native-test','State' => 'Running','CPU' => 2,'Memory' => 4096]]])];
    }
    if (str_contains($host,'alidns') && str_contains($query['Action'] ?? '', 'CloudGtm')) {
        require_once __DIR__.'/gtm-fixture.php';
        return gtmFixture($query);
    }
    if (str_contains($host,'alidns') && ($query['Action'] ?? '')==='DescribeDomains') return ['status'=>200,'body'=>json_encode(['TotalCount'=>2,'Domains'=>['Domain'=>[['DomainId'=>'private-domain-id','DomainName'=>'example.com'],['DomainId'=>'private-domain-id-2','DomainName'=>'example.net']]]])];
    if (str_contains($host,'alidns') && ($query['Action'] ?? '')==='DescribeDomainInfo') return ['status'=>200,'body'=>json_encode(['MinTtl'=>60,'AvailableTtls'=>['AvailableTtl'=>['[60,600,1800,3600]']]])];
    if (str_contains($host, 'alidns')) {
        return ['status' => 200,'body' => json_encode(['RequestId' => 'test','TotalCount' => 1,'DomainRecords' => ['Record' => [['RecordId' => 'test-record','RR' => 'www','Type' => 'A','Value' => '192.0.2.10','TTL' => 600]]]])];
    }
    if (str_contains($host,'cloudflare.com') && str_contains($path,'/dns_records')) return ['status'=>200,'body'=>json_encode(['success'=>true,'result'=>[['id'=>'record-test','name'=>str_contains($path,'/test-zone-2/')?'www.example.net':'www.example.com','type'=>'A','content'=>'192.0.2.1','ttl'=>123,'proxied'=>false]],'result_info'=>['total_count'=>1]])];
    if (str_contains($host, 'cloudflare.com')) {
        return ['status' => 200,'body' => '{"success":true,"result":[{"id":"test-zone","name":"example.com","status":"active"},{"id":"test-zone-2","name":"example.net","status":"active"}],"result_info":{"total_count":2}}'];
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

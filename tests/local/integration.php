<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$root = sys_get_temp_dir() . '/cloud-integration-' . bin2hex(random_bytes(8));
$app->useStoragePath($root);
foreach (['framework/cache/data','framework/views','logs'] as $path) mkdir($root.'/'.$path,0700,true);
config(['app.key'=>'base64:'.base64_encode(str_repeat('t',32)), 'cache.stores.file.path'=>$root.'/framework/cache/data']);
function verify($value, $message) { if (!$value) throw new RuntimeException($message); }
try {
    $app->useEnvironmentPath($root);
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle(Illuminate\Http\Request::create('/','GET'));
    verify($response->getStatusCode() === 302 && str_ends_with($response->headers->get('Location'),'/setup'), 'First visit redirects to setup');
    $response = $kernel->handle(Illuminate\Http\Request::create('/setup','GET'));
    verify($response->getStatusCode() === 200, 'Setup form renders');
    $cookie = $response->headers->getCookies()[0]->getValue();
    $bad = $kernel->handle(Illuminate\Http\Request::create('/setup','POST',['email'=>'bad@example.com'],['cloud_setup'=>$cookie]));
    verify($bad->getStatusCode() === 419 && !App\Models\User::exists(), 'Setup requires CSRF token');
    $response = $kernel->handle(Illuminate\Http\Request::create('/setup','POST',['_setup_token'=>$cookie,'email'=>'owner@example.com','password'=>'owner-password-123','password_confirmation'=>'owner-password-123'],['cloud_setup'=>$cookie]));
    verify($response->getStatusCode() === 302, 'Setup succeeds');
    verify(is_file($root.'/.env') && App\Models\User::where('email','owner@example.com')->exists(), 'Setup persists config and owner');
    $savedEnvironment = file_get_contents($root.'/.env');
    $response = $kernel->handle(Illuminate\Http\Request::create('/setup','POST',['email'=>'intruder@example.com']));
    verify($response->getStatusCode() === 302 && !App\Models\User::where('email','intruder@example.com')->exists(), 'Installed setup cannot create more admins');
    verify(file_get_contents($root.'/.env') === $savedEnvironment, 'Installed setup preserves key');
    app(App\Support\FileStore\Initialize::class)->run();
    app(App\Support\FileStore\Initialize::class)->run();
    verify(App\Models\ClientRemoteConfig::count() === 1, 'Initialization must be idempotent');
    $draft = App\Models\ClientRemoteConfig::first();
    verify(is_array($draft->content) && $draft->config_version === 1, 'Config accessors');
    $target = App\Models\ClientStorageTarget::create(['name'=>'test','provider'=>'aliyun_oss','enabled'=>true,'secret_key_encrypted'=>encrypt('test')]);
    verify($target->enabled === true && !array_key_exists('secret_key_encrypted',$target->toArray()),'Casts and secret hiding');
    $publication = App\Models\ClientConfigPublication::updateOrCreate(['config_id'=>$draft->id,'target_id'=>$target->id],['status'=>'queued']);
    verify($publication->config->id === $draft->id && $publication->target->name === 'test', 'Publication relations');
    $json = App\Models\ClientConfigPublication::with(['target:id,name,provider','config:id,config_version'])->get()->toArray();
    verify($json[0]['target']['name'] === 'test' && !isset($json[0]['target']['secret_key_encrypted']), 'Relation serialization');
    verify(validator(['id'=>$target->id],['id'=>'required|exists:v2_client_storage_target,id'])->passes(),'Local presence validation');
    verify(validator(['id'=>999],['id'=>'exists:v2_client_storage_target,id'])->fails(),'Reject missing IDs');
    $target->name='changed';$target->save();verify($target->fresh()->name==='changed','Fresh');
    $admin = App\Models\User::create(['email'=>'admin@example.com','password'=>password_hash('test-password-123', PASSWORD_DEFAULT),'is_admin'=>true,'token'=>'test']);
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $send = function ($method, $path, $data = [], $token = null) use ($kernel) {
        $request = Illuminate\Http\Request::create($path,$method,$data,[],[],['HTTP_ACCEPT'=>'application/json','HTTP_USER_AGENT'=>'CloudTest','HTTP_AUTHORIZATION'=>$token ?: '']);
        $response = $kernel->handle($request);
        verify($response->getStatusCode() === 200, $path . ': ' . $response->getContent());
        return json_decode($response->getContent(),true);
    };
    $login = $send('POST','/api/v1/passport/auth/login',['email'=>'admin@example.com','password'=>'test-password-123']);
    $token = $login['data']['auth_data'];
    foreach (['client-config','aliyun-dns','cloudflare-dns','cloud-account'] as $module) $send('GET','/api/v1/admin/'.$module.'/overview',[],$token);
    $send('GET','/api/v1/user/info',[],$token);
    $send('POST','/api/v1/admin/client-config/version/clone',['id'=>$draft->id],$token);
    $target->enabled = false; $target->save();
    (new App\Jobs\PublishClientRemoteConfig($publication->id))->handle(app(App\Services\ClientConfigCryptoService::class),app(App\Services\ClientConfigStorageService::class));
    verify($publication->fresh()->status === 'failed', 'Publishing failure must persist');
    verify($draft->fresh()->status === 'partial', 'Failed config status');

    // Legacy credentials are centralized once, and rotation is resolved at use time.
    $credentials=app(App\Services\CloudCredentials::class);
    $legacy=App\Models\ClientStorageTarget::create(['name'=>'legacy','provider'=>'aliyun_oss','enabled'=>true,'access_key_id_encrypted'=>encrypt('legacy-id',false),'secret_key_encrypted'=>encrypt('legacy-secret',false)]);
    $dns=App\Models\AliyunDnsAccount::create(['name'=>'legacy-dns','enabled'=>true,'access_key_id_encrypted'=>encrypt('legacy-id',false),'access_key_secret_encrypted'=>encrypt('legacy-secret',false)]);
    $credentials->migrate();$legacy=$legacy->fresh();$dns=$dns->fresh();
    verify($legacy->cloud_account_id === $dns->cloud_account_id,'Matching legacy credentials share one account');
    verify(empty($legacy->getAttributes()['secret_key_encrypted']) && empty($dns->getAttributes()['access_key_secret_encrypted']),'Duplicate secrets removed');
    $shared=App\Models\CloudAccount::findOrFail($legacy->cloud_account_id);
    $shared->access_key_secret_encrypted=encrypt('rotated-secret',false);$shared->save();
    verify(decrypt($legacy->secret_key_encrypted,false)==='rotated-secret' && decrypt($dns->access_key_secret_encrypted,false)==='rotated-secret','Credential rotation reaches both modules');
    $credentials->migrate();verify(count($credentials->references($shared->id))===2,'Migration idempotent');
    $reject=Illuminate\Http\Request::create('/api/v1/admin/cloud-account/drop','POST',['id'=>$shared->id],[],[],['HTTP_AUTHORIZATION'=>$token]);
    verify($kernel->handle($reject)->getStatusCode()===422,'Referenced account cannot be deleted');
    $shared->enabled=false;$shared->save();
    try { $legacy->assertCloudAccountEnabled();throw new RuntimeException('Disabled linked account accepted'); } catch (Symfony\Component\HttpKernel\Exception\HttpException $expected) { verify($expected->getStatusCode()===422,'Disabled account rejected'); }
    $shared->enabled=true;$shared->save();

    // Cloud responses are mocked: tests must never operate on a real instance.
    $fakeApi=new class extends App\Services\CloudBillingService {
        public $calls=[];
        public function resourceRequest(App\Models\CloudAccount $account,$action,array $parameters=[],$region=null) {
            $this->calls[]=[$account->provider,$action,$parameters,$region];
            if ($action==='DescribeInstances' && $account->provider==='aliyun') return ['TotalCount'=>1,'Instances'=>['Instance'=>[['InstanceId'=>'i-test','InstanceName'=>'mock','Status'=>'Running','Cpu'=>2,'Memory'=>4096]]]];
            if ($action==='DescribeInstances') return ['TotalCount'=>1,'InstanceSet'=>[['InstanceId'=>'ins-test','InstanceState'=>'RUNNING','CPU'=>2,'Memory'=>4]]];
            if ($action==='DescribeUHostInstance') return ['TotalCount'=>1,'UHostSet'=>[['UHostId'=>'uhost-test','State'=>'Running','CPU'=>2,'Memory'=>4096]]];
            return ['RequestId'=>'mock-request'];
        }
    };
    $compute=new App\Services\CloudComputeService($fakeApi);
    foreach (['aliyun','tencent','ucloud'] as $provider) {
        $account=new App\Models\CloudAccount(['provider'=>$provider,'enabled'=>true]);
        $list=$compute->instances($account,'test-region',2,'org-test');verify($list['total']===1 && $list['items'][0]['memory_gb']==4,'Normalize '.$provider.' instances');
        foreach (['start','stop','reboot'] as $operation) verify($compute->operate($account,'test-region',$list['items'][0]['id'],$operation,'org-test')['RequestId']==='mock-request','Compute action mapping');
    }
    app()->instance(App\Services\CloudComputeService::class,$compute);
    $send('GET','/api/v1/admin/cloud-resource/instances',['account_id'=>$shared->id,'region'=>'cn-hangzhou','page'=>1],$token);
    $bad=Illuminate\Http\Request::create('/api/v1/admin/cloud-resource/instance/operate','POST',['account_id'=>$shared->id,'region'=>'cn-hangzhou','instance_id'=>'i-test','operation'=>'stop','confirmation'=>'wrong'],[],[],['HTTP_AUTHORIZATION'=>$token]);
    verify($kernel->handle($bad)->getStatusCode()===422,'Instance operation requires matching confirmation');
    $send('POST','/api/v1/admin/cloud-resource/instance/operate',['account_id'=>$shared->id,'region'=>'cn-hangzhou','instance_id'=>'i-test','operation'=>'start','confirmation'=>'i-test'],$token);
    verify(count($send('GET','/api/v1/admin/cloud-resource/operations',['account_id'=>$shared->id],$token)['data'])===1,'Successful submission audited');

    $history=[];$mock=new GuzzleHttp\Handler\MockHandler([
        new GuzzleHttp\Psr7\Response(200,[], '<ListAllMyBucketsResult><Buckets><Bucket><Name>test-bucket</Name><Location>oss-cn-hangzhou</Location></Bucket></Buckets></ListAllMyBucketsResult>'),
        new GuzzleHttp\Psr7\Response(200,[], '<ListBucketResult><CommonPrefixes><Prefix>folder/</Prefix></CommonPrefixes><Contents><Key>中文.txt</Key><Size>123</Size></Contents><IsTruncated>true</IsTruncated><NextMarker>中文.txt</NextMarker></ListBucketResult>'),
        new GuzzleHttp\Psr7\Response(200,[], ''), new GuzzleHttp\Psr7\Response(204),
    ]);
    $stack=GuzzleHttp\HandlerStack::create($mock);$stack->push(GuzzleHttp\Middleware::history($history));
    $objects=new App\Services\CloudObjectService(new GuzzleHttp\Client(['handler'=>$stack]));
    verify($objects->buckets($shared,'cn-hangzhou')['items'][0]['region']==='cn-hangzhou','Bucket region normalization');
    $listed=$objects->objects($shared,'cn-hangzhou','test-bucket','','');verify($listed['items'][0]['directory'] && $listed['next_marker']==='中文.txt','Directory and cursor parsing');
    $objects->request($shared,'PUT','cn-hangzhou','test-bucket','中文.txt',[],'content');
    $objects->request($shared,'DELETE','cn-hangzhou','test-bucket','中文.txt');
    verify(str_starts_with($history[0]['request']->getHeaderLine('Authorization'),'OSS4-HMAC-SHA256 '),'OSS uses V4 signing');
    verify((string)$history[2]['request']->getBody()==='content' && !str_contains((string)$history[2]['request']->getUri(),'legacy-secret'),'Upload body and no credentials in URL');
    verify($history[3]['request']->getMethod()==='DELETE','Object delete method');

    foreach (['tencent'=>'q-sign-algorithm=sha1','ucloud'=>'AWS4-HMAC-SHA256'] as $provider=>$scheme) {
        $requests=[];$mock=new GuzzleHttp\Handler\MockHandler([new GuzzleHttp\Psr7\Response(200,[],'<ListBucketResult><Contents><Key>one.txt</Key><Size>1</Size></Contents></ListBucketResult>')]);
        $stack=GuzzleHttp\HandlerStack::create($mock);$stack->push(GuzzleHttp\Middleware::history($requests));
        $api=new App\Services\CloudObjectService(new GuzzleHttp\Client(['handler'=>$stack]));
        $account=new App\Models\CloudAccount(['provider'=>$provider,'enabled'=>true,'access_key_id_encrypted'=>encrypt('test-id',false),'access_key_secret_encrypted'=>encrypt('test-secret',false)]);
        verify($api->objects($account,$provider==='tencent'?'ap-guangzhou':'cn-bj','test-bucket','','')['items'][0]['key']==='one.txt','Parse '.$provider.' objects');
        verify(str_starts_with($requests[0]['request']->getHeaderLine('Authorization'),$scheme),'Sign '.$provider.' requests');
        verify($requests[0]['request']->getUri()->getScheme()==='https','Object requests require TLS');
    }
    echo "PASS: unified credential migration, rotation, reference protection, compute mappings, resource authorization, object parsing and signing\n";
    echo "PASS: initialization, queries, casts, secrets, relations, validation, persistence\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); } rmdir($root);
}

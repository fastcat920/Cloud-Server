<?php

declare(strict_types=1);

namespace CloudDesk;

final class ApiError extends \RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }
}
final class Api
{
    private Store $store;
    private Security $security;
    private Cloud $cloud;
    private Objects $objects;
    private Configs $configs;
    private array $input;
    public function __construct(string $directory)
    {
        $this->store = new Store($directory);
        $this->security = new Security($this->store);
        $this->cloud = new Cloud($this->security);
        $this->objects = new Objects($this->cloud);
        $this->configs = new Configs($this->security);
    }
    private function text(string $key, int $max = 1024, bool $required = true, bool $trim = true): string
    {
        $value = $this->input[$key] ?? '';
        if (!is_scalar($value)) {
            throw new ApiError('字段格式错误：'.$key);
        }
        $value = ($trim && !in_array($key, ['password','old','confirmation'], true)) ? trim((string)$value) : (string)$value;
        if (($required && $value === '') || strlen($value) > $max) {
            throw new ApiError('请检查字段：'.$key);
        }
        return $value;
    }
    private function id(string $key = 'id'): int
    {
        $id = filter_var($this->input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) {
            throw new ApiError('记录 ID 无效');
        }
        return $id;
    }
    private function account(): array
    {
        $a = Store::find($this->store->read(), 'accounts', $this->id('account_id'));
        if (!$a['enabled']) {
            throw new ApiError('账号已停用');
        }
        return $a;
    }
    private function region(): string
    {
        $region = $this->text('region', 50);
        if (!preg_match('/^[a-z0-9-]+$/', $region)) {
            throw new ApiError('地域 ID 无效');
        }
        return $region;
    }
    private function confirmation(string $value): void
    {
        if (!hash_equals($value, $this->text('confirmation'))) {
            throw new ApiError('确认内容不一致');
        }
    }
    private function record(string $action, string $resource, string $status = 'success'): void
    {
        $this->store->transaction(fn (array &$s) => Store::event($s, $action, $resource, $status));
    }
    private function limited(string $scope, int $limit, int $seconds): void
    {
        $key = hash('sha256', $scope.'|'.($_SERVER['REMOTE_ADDR'] ?? ''));
        $allowed = $this->store->transaction(function (array &$s) use ($key, $limit, $seconds) {
            $now = time();
            $s['rates'] = array_filter($s['rates'], fn ($r) => $r['until'] > $now);
            $r = $s['rates'][$key] ?? ['count' => 0,'until' => $now + $seconds];
            $r['count']++;
            $s['rates'][$key] = $r;
            return $r['count'] <= $limit;
        });
        if (!$allowed) {
            throw new ApiError('请求过于频繁，请稍后重试', 429);
        }
    }
    private function safe(array $state): array
    {
        unset($state['admin'],$state['rates'],$state['sequences'],$state['gtm_changes'],$state['notification_channels'],$state['notification_sources'],$state['notification_jobs']);
        $state['restore_backups'] = array_map('basename',glob($this->store->directory().'/backups/before-restore-*.php') ?: []);
        rsort($state['restore_backups']);
        foreach ($state['versions'] as &$v) $v['edit_token'] = Checks::token($v);
        unset($v);
        foreach ($state['publications'] as &$p) unset($p['frozen']);
        unset($p);
        foreach ($state['accounts'] as &$a) {
            $a['has_credentials'] = !empty($a['credentials']);
            unset($a['credentials']);
            $a['references'] = array_values(array_map(fn ($t) => $t['name'], array_filter($state['targets'], fn ($t) => (int)$t['account_id'] === (int)$a['id'])));
        }
        unset($a);
        $state['settings']['has_xor_key'] = !empty($state['settings']['xor_key']);
        unset($state['settings']['xor_key'],$state['settings']['signing_secret']);
        $state['events'] = array_reverse($state['events']);
        return $state;
    }
    public function run(): never
    {
        if (($_GET['api'] ?? '')==='notify.webhook') $this->webhook();
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_save_handler(new Sessions($this->store->directory()), true);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', '43200');
        session_name('cloud_desk');
        session_set_cookie_params(['lifetime' => 0,'path' => '/','secure' => $secure,'httponly' => true,'samesite' => 'Strict']);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        $csrf = $_SESSION['csrf'];
        $state = $this->store->read();
        $action = (string)($_GET['api'] ?? 'boot');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        try {
            $write = $method === 'POST';
            $reads = ['notify.list','boot','overview','regions','instances','buckets','objects','cf.zones','cf.records','dns.list','gtm.list','gtm.detail','gtm.pools','gtm.addresses','gtm.monitors','gtm.catalog','gtm.options','gtm.resource','gtm.references','gtm.history'];
            if (!$write && !in_array($action, $reads, true)) {
                throw new ApiError('此操作需要 POST 请求', 405);
            }
            if ($write) {
                if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
                    throw new ApiError('页面已过期，请刷新后重试', 419);
                }
                if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
                    $raw = file_get_contents('php://input');
                    if (strlen($raw) > 2097152) {
                        throw new ApiError('请求内容超过 2 MB');
                    }
                    $this->input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($this->input)) {
                        throw new ApiError('请求格式无效');
                    }
                } else {
                    $this->input = $_POST;
                }
            } else {
                $this->input = $_GET;
            }
            $authenticated = isset($state['admin'],$_SESSION['auth']) && hash_equals((string)($state['admin']['epoch'] ?? ''), (string)$_SESSION['auth']) && ($_SESSION['expires'] ?? 0) > time();
            if ($action === 'boot') {
                $this->respond(['installed' => isset($state['admin']),'authenticated' => $authenticated,'csrf' => $csrf,'email' => $authenticated ? $state['admin']['email'] : null,'version' => '2.1.1','environment' => ['php' => PHP_VERSION,'missing' => array_values(array_filter(['openssl','curl','simplexml'], fn ($e) => !extension_loaded($e)))]]);
            }
            if ($action === 'setup') {
                $this->limited('setup', 10, 600);
                $email = $this->text('email', 64);
                $password = $this->text('password', 256);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
                    throw new ApiError('请输入有效邮箱和至少 12 位密码');
                }
                $this->store->transaction(function (array &$s) use ($email, $password) {
                    if (isset($s['admin'])) {
                        throw new ApiError('已完成初始化', 409);
                    }
                    $s['admin'] = ['email' => $email,'password' => password_hash($password, PASSWORD_DEFAULT),'epoch' => bin2hex(random_bytes(16))];
                    $s['versions'][] = ['id' => Store::id($s, 'versions'),'version' => 1,'content' => Configs::defaults(),'mode' => 'plain','status' => 'draft','summary' => '初始配置','created_at' => time()];
                    Store::event($s, 'setup', $email);
                });
                $this->respond(true);
            }
            if ($action === 'login') {
                $this->limited('login', 8, 300);
                $email = $this->text('email', 64);
                $password = $this->text('password', 256);
                $dummy = '$2y$10$0QtJf92ho6kW3UlZwJvpiOe1AVIg4IuhBaDxliVRQf2pQ26oTFOF6';
                if (!password_verify($password, $state['admin']['password'] ?? $dummy) || !isset($state['admin']) || strcasecmp($email, $state['admin']['email']) !== 0) {
                    throw new ApiError('邮箱或密码错误', 401);
                }session_regenerate_id(true);
                $_SESSION['auth'] = $state['admin']['epoch'];
                $_SESSION['expires'] = time() + 43200;
                $this->respond(true);
            }
            if (!$authenticated) {
                throw new ApiError('请先登录', 401);
            }
            if ($action === 'logout') {
                $_SESSION = [];
                session_destroy();
                $this->respond(true);
            }
            session_write_close();
            $result = $this->dispatch($action);
            $this->respond($result);
        } catch (ApiError $e) {
            $this->respond(null, $e->status, $e->getMessage());
        } catch (\JsonException $e) {
            $this->respond(null, 422, 'JSON 格式错误，请检查后重试');
        } catch (GtmRequestError $e) {
            $this->respond(null,422,$e->getMessage(),$e->details);
        } catch (\Throwable $e) {
            $this->respond(null, 422, $e instanceof \RuntimeException ? $e->getMessage() : '操作失败，请检查输入与服务器运行环境');
        }
    }
    private function webhook(): never
    {
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') throw new ApiError('回调需要 POST 请求',405);
            $service=new Notifications($this->store,$this->security);
            $source=(int)($_GET['source'] ?? 0);$key=is_string($_GET['key'] ?? null)?$_GET['key']:'';
            $service->authorize($source,$key);
            $raw=file_get_contents('php://input',false,null,0,131073);
            if (strlen($raw)>131072) throw new ApiError('回调内容超过 128 KB',413);
            $payload=str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''),'application/json')?json_decode($raw,true,16,JSON_THROW_ON_ERROR):$_POST;
            if (!is_array($payload)) throw new ApiError('回调内容格式不正确');
            $result=$service->receive($source,$key,$payload);
            $body=json_encode(['errcode'=>0,'errmsg'=>'ok','code'=>0,'message'=>'success','data'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        } catch (ApiError $e) {$this->respond(null,$e->status,$e->getMessage());}
        catch (\Throwable) {$this->respond(null,422,'回调内容无法处理，请检查 JSON 或表单格式');}
        header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('Content-Length: '.strlen($body));echo $body;
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        try { $service->process(1,$result['id']); } catch (\Throwable) { /* A saved job remains retryable by the worker. */ }
        exit;
    }
    private function respond(mixed $data, int $status = 200, string $error = '', ?array $details = null): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['data' => $data,'error' => $error]+($details===null?[]:['error_details'=>$details]), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    private function dispatch(string $action): mixed
    {
        $exclusive = in_array($action,['target.save','target.delete','plan.save','account.save','account.delete','config.settings','restore','import.v1','template.delete'],true) ? (new Releases($this->store,$this->security,$this->objects,$this->configs))->lock() : null;
        try {
        switch ($action) {
            case 'notify.list':return (new Notifications($this->store,$this->security))->listing()+['worker_path'=>dirname(__DIR__).'/bin/notify-worker.php'];
            case 'notify.channel.save':return (new Notifications($this->store,$this->security))->saveChannel($this->input);
            case 'notify.source.save':return (new Notifications($this->store,$this->security))->saveSource($this->input);
            case 'notify.channel.delete':case 'notify.source.delete':$this->confirmation((string)$this->id());return (new Notifications($this->store,$this->security))->remove($action==='notify.channel.delete'?'channel':'source',$this->id());
            case 'notify.source.rotate':$this->confirmation((string)$this->id());return (new Notifications($this->store,$this->security))->rotate($this->id());
            case 'notify.verify':$this->limited('notify.verify',10,60);return (new Notifications($this->store,$this->security))->verify($this->id());
            case 'notify.test':$this->limited('notify.test',10,60);return (new Notifications($this->store,$this->security))->test($this->id());
            case 'notify.retry':$this->limited('notify.retry',10,60);return (new Notifications($this->store,$this->security))->retry($this->id());
            case 'notify.process':return (new Notifications($this->store,$this->security))->process(5);

            case 'overview':Plans::migrate($this->store);
                (new Releases($this->store,$this->security,$this->objects,$this->configs))->recover();
                return $this->safe($this->store->read());
            case 'password':$old = $this->text('old', 256);
                $new = $this->text('password', 256);
                if (strlen($new) < 12) {
                    throw new ApiError('新密码至少 12 位');
                }
                $this->store->transaction(function (array &$s) use ($old, $new) {
                    if (!password_verify($old, $s['admin']['password'])) {
                        throw new ApiError('原密码错误');
                    }
                    $s['admin']['password'] = password_hash($new, PASSWORD_DEFAULT);
                    $s['admin']['epoch'] = bin2hex(random_bytes(16));
                    Store::event($s, 'password', '管理员');
                });
                return true;
            case 'account.save':
                $name = $this->text('name', 100);
                $provider = $this->text('provider', 20);
                if (!in_array($provider, ['aliyun','tencent','ucloud','cloudflare'], true)) {
                    throw new ApiError('云厂商无效');
                }
                $id = (int)($this->input['id'] ?? 0);
                $site = $this->text('site', 20, false) ?: 'china';
                if (!in_array($site, ['china','international'], true)) {
                    throw new ApiError('站点无效');
                }
                return $this->store->transaction(function (array &$s) use ($name, $provider, $id, $site) {
                    $row = $id ? Store::find($s, 'accounts', $id) : ['id' => Store::id($s, 'accounts'),'credentials' => []];
                    if ($id && $row['provider'] !== $provider) {
                        foreach ($s['targets'] as $t) {
                            if ($t['account_id'] === $id) {
                                throw new ApiError('此账号被发布目标引用，不能更换厂商');
                            }
                        }
                        $row['credentials'] = [];
                    }
                    $row = array_merge($row, ['name' => $name,'provider' => $provider,'site' => $site,'enabled' => (bool)($this->input['enabled'] ?? true)]);
                    $changed = false;
                    foreach (['key','secret','token'] as $field) {
                        $value = $this->text($field, 4000, false);
                        if ($field === 'token' && $provider === 'cloudflare') {
                            $value = preg_replace('/^(?:Authorization:\s*)?Bearer\s+/i', '', $value);
                        }
                        if ($value !== '') {
                            $row['credentials'][$field] = $this->security->encrypt($value);
                            $changed = true;
                        }
                    }
                    if (!empty($this->input['clear_token']) && $provider !== 'cloudflare') {
                        $row['credentials']['token'] = '';
                        $changed = true;
                    }
                    if (($provider === 'cloudflare' && empty($row['credentials']['token'])) || ($provider !== 'cloudflare' && (empty($row['credentials']['key']) || empty($row['credentials']['secret'])))) {
                        throw new ApiError('请填写完整的访问凭证');
                    }
                    if ($changed) {
                        unset($row['billing']);
                    }Store::put($s, 'accounts', $row);
                    Store::event($s, 'account.save', $name);
                    return $row['id'];
                });
            case 'account.delete':$id = $this->id();
                return $this->store->transaction(function (array &$s) use ($id) {
                    $row = Store::find($s, 'accounts', $id);
                    $this->confirmation($row['name']);
                    foreach ($s['targets'] as $t) {
                        if ((int)$t['account_id'] === $id) {
                            throw new ApiError('账号被发布目标「'.$t['name'].'」使用，请先解除关联');
                        }
                    }
                    $s['accounts'] = array_values(array_filter($s['accounts'], fn ($r) => $r['id'] !== $id));
                    Store::event($s, 'account.delete', $row['name']);
                    return true;
                });
            case 'billing':$a = $this->account();
                try {
                    $billing = $this->cloud->billing($a);
                    $this->store->transaction(function (array &$s) use ($a, $billing) {
                        $row = Store::find($s, 'accounts', $a['id']);
                        if ($row['credentials'] !== $a['credentials']) {
                            throw new ApiError('账号凭证已变化，请重新查询');
                        }
                        $row['billing'] = $billing;
                        unset($row['billing_error']);
                        Store::put($s, 'accounts', $row);
                    });
                    return $billing;
                } catch (\RuntimeException $e) {
                    $this->store->transaction(function (array &$s) use ($a, $e) {
                        $row = Store::find($s, 'accounts', $a['id']);
                        $row['billing_error'] = $e->getMessage();
                        Store::put($s, 'accounts', $row);
                    });
                    throw $e;
                }
            case 'regions':return $this->cloud->regions($this->account());
            case 'instances':return $this->cloud->instances($this->account(), $this->region(), max(1, (int)($this->input['page'] ?? 1)), $this->text('project', 100, false));
            case 'instance.action':$a = $this->account();
                $id = $this->text('instance', 100);
                $this->confirmation($id);
                $operation = $this->text('operation', 20);
                $result = $this->cloud->instanceAction($a, $this->region(), $id, $operation, $this->text('project', 100, false));
                $this->record('instance.'.$operation, $a['name'].'/'.$id);
                return ['submitted' => true,'request_id' => $result['RequestId'] ?? null];
            case 'buckets':return $this->objects->buckets($this->account(), $this->region(), $this->text('marker', 1024, false));
            case 'objects':return $this->objects->list($this->account(), $this->region(), $this->text('bucket', 255), $this->text('prefix', 1024, false), $this->text('marker', 1024, false));
            case 'object.upload':$a = $this->account();
                $key = $this->text('key', 1024, true, false);
                $this->confirmation($key);
                $file = $this->file(20 * 1024 * 1024);
                $bucket = $this->text('bucket', 255);
                $this->objects->request($a, 'PUT', $this->region(), $bucket, $key, body:$file);
                $this->record('object.upload', $bucket.'/'.$key);
                return true;
            case 'object.delete':$a = $this->account();
                $key = $this->text('key', 1024, true, false);
                $this->confirmation($key);
                $bucket = $this->text('bucket', 255);
                $this->objects->request($a, 'DELETE', $this->region(), $bucket, $key);
                $this->record('object.delete', $bucket.'/'.$key);
                return true;
            case 'object.download':$key = $this->text('key', 1024, true, false);
                $response = $this->objects->request($this->account(), 'GET', $this->region(), $this->text('bucket', 255), $key);
                header('Content-Type: application/octet-stream');
                header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode(basename($key)));
                header('Cache-Control: no-store');
                echo $response['body'];
                exit;
            case 'cf.zones':$a = $this->account();
                $this->provider($a, 'cloudflare');
                return $this->cloud->cloudflare($a, 'GET', '/zones', ['per_page' => 50,'page' => max(1, (int)($this->input['page'] ?? 1))]);
            case 'cf.records':$a = $this->account();
                $this->provider($a, 'cloudflare');
                return $this->cloud->cloudflare($a, 'GET', '/zones/'.rawurlencode($this->text('zone', 100)).'/dns_records', ['page' => max(1, (int)($this->input['page'] ?? 1)),'per_page' => 50,'search' => $this->text('search', 255, false)]);
            case 'cf.test':$a = $this->account();
                $this->provider($a, 'cloudflare');
                return $this->cloud->cloudflare($a, 'GET', '/user/tokens/verify');
            case 'cf.save':case 'cf.delete':$a = $this->account();
                $this->provider($a, 'cloudflare');
                $path = '/zones/'.rawurlencode($this->text('zone', 100)).'/dns_records';
                $id = $this->text('record', 100, false);
                if ($id) {
                    $path .= '/'.rawurlencode($id);
                }
                $payload = $this->input['payload'] ?? [];
                if (!is_array($payload)) {
                    throw new ApiError('解析内容无效');
                }
                if ($action === 'cf.delete') {
                    if (!$id) {
                        throw new ApiError('缺少记录 ID');
                    }
                    $this->confirmation($id);
                }
                $result = $this->cloud->cloudflare($a, $action === 'cf.delete' ? 'DELETE' : ($id ? 'PATCH' : 'POST'), $path, $action === 'cf.delete' ? [] : $payload);
                $this->record($action, $a['name'].'/'.$path);
                return $result;
            case 'dns.list':$a = $this->account();
                $this->provider($a, 'aliyun');
                $domain = $this->text('domain', 253, false);
                $params = ['PageNumber' => max(1, (int)($this->input['page'] ?? 1)),'PageSize' => 50];
                if ($domain) {
                    $params['DomainName'] = $domain;
                }
                $result=$this->cloud->rpc($a, $domain ? 'DescribeDomainRecords' : 'DescribeDomains', $params, service:'alidns');
                if ($domain) {
                    try { $info=$this->cloud->rpc($a,'DescribeDomainInfo',['DomainName'=>$domain,'NeedDetailAttributes'=>true],service:'alidns'); }
                    catch (\RuntimeException) { $info=[]; }
                    $result['TtlOptions']=Cloud::dnsTtlOptions($info);
                }
                return $result;
            case 'dns.save':case 'dns.delete':$a = $this->account();
                $this->provider($a, 'aliyun');
                $id = $this->text('record', 100, false);
                if ($action === 'dns.delete') {
                    $this->confirmation($id);
                    $params = ['RecordId' => $id];
                    $operation = 'DeleteDomainRecord';
                } else {
                    $payload = $this->input['payload'] ?? [];
                    $params = ['RR' => $payload['RR'] ?? '@','Type' => $payload['Type'] ?? 'A','Value' => $payload['Value'] ?? '','TTL' => max(1, (int)($payload['TTL'] ?? 600))];
                    if (isset($payload['Priority']) && in_array($params['Type'], ['MX','SRV'], true)) {
                        $params['Priority'] = (int)$payload['Priority'];
                    }
                    if ($id) {
                        $params['RecordId'] = $id;
                    } else {
                        $params['DomainName'] = $this->text('domain', 253);
                    }
                    $operation = $id ? 'UpdateDomainRecord' : 'AddDomainRecord';
                }
                $result = $this->cloud->rpc($a, $operation, $params, service:'alidns');
                $this->record($action, $a['name'].'/'.$id);
                return $result;
            case 'gtm.list':case 'gtm.detail':case 'gtm.pools':case 'gtm.addresses':case 'gtm.monitors':case 'gtm.action':case 'gtm.catalog':case 'gtm.options':case 'gtm.resource':case 'gtm.references':case 'gtm.history':case 'gtm.preview':return $this->gtm($action);
            case 'target.save':$id = (int)($this->input['id'] ?? 0);
                $a = $this->account();
                if ($a['provider'] === 'cloudflare') {
                    throw new ApiError('Cloudflare 不能作为对象存储账号');
                }
                $region = $this->region();
                $name = $this->text('name', 100);
                $bucket = $this->text('bucket', 255);
                $key = $this->text('key', 1024, true, false);
                if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,250}$/', $bucket)) {
                    throw new ApiError('Bucket 格式错误');
                }
                $url = $this->text('url', 2000, false);
                if ($url && (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'], true))) {
                    throw new ApiError('公开地址无效');
                }
                return $this->store->transaction(function (array &$s) use ($id, $a, $region, $name, $bucket, $key, $url) {
                    $row = ['id' => $id ?: Store::id($s, 'targets'),'account_id' => $a['id'],'region' => $region,'name' => $name,'bucket' => $bucket,'key' => $key,'url' => $url ?: Objects::publicUrl($a['provider'], $region, $bucket, $key),'enabled' => (bool)($this->input['enabled'] ?? true),'public_read' => (bool)($this->input['public_read'] ?? false)];
                    if ($id) {
                        Store::find($s, 'targets', $id);
                    }Store::put($s, 'targets', $row);
                    Store::event($s, 'target.save', $name);
                    return $row['id'];
                });
            case 'target.delete':$id = $this->id();
                return $this->store->transaction(function (array &$s) use ($id) {
                    $row = Store::find($s, 'targets', $id);
                    $this->confirmation($row['name']);
                    foreach ($s['publications'] as $p) {
                        if ($p['target_id'] === $id && $p['status'] === 'publishing') {
                            throw new ApiError('发布进行中，不能删除');
                        }
                    }
                    $s['targets'] = array_values(array_filter($s['targets'], fn ($r) => $r['id'] !== $id));
                    if (isset($s['plans'])) {
                        foreach ($s['plans'] as &$plan) {
                            $plan['targets'] = array_values(array_diff($plan['targets'], [$id]));
                        }
                    }
                    unset($plan);
                    return true;
                });
            case 'target.test':$s = $this->store->read();
                $target = Store::find($s, 'targets', $this->id());
                $a = Store::find($s, 'accounts', $target['account_id']);
                $key = '.cloud-desk-test-'.bin2hex(random_bytes(8));
                $content = bin2hex(random_bytes(16));
                $uploaded = false;
                try {
                    $this->objects->request($a, 'PUT', $target['region'], $target['bucket'], $key, body:$content);
                    $uploaded = true;
                    $result = $this->objects->request($a, 'GET', $target['region'], $target['bucket'], $key);
                    if (!hash_equals($content, $result['body'])) {
                        throw new ApiError('上传后读取内容不一致');
                    }
                } finally {
                    if ($uploaded) {
                        $this->objects->request($a, 'DELETE', $target['region'], $target['bucket'], $key);
                    }
                }
                return true;
            case 'template.delete':
                $id = $this->id();
                return $this->store->transaction(function (array &$s) use ($id) {
                    $template = Store::find($s,'templates',$id);
                    if (!empty($template['builtin'])) throw new ApiError('系统内置模板不能删除',409);
                    $this->confirmation($template['name']);
                    $references=[];
                    foreach ($s['plans'] ?? [] as $plan) if ($plan['template_id'] === $id) $references[]='方案「'.$plan['name'].'」';
                    foreach ($s['versions'] as $version) if (($version['template_id'] ?? null) === $id) $references[]='修订 r'.($version['revision'] ?? $version['version']).'（ID '.$version['id'].'）';
                    if ($references) throw new ApiError('模板仍被使用：'.implode('、',array_slice($references,0,8)).(count($references)>8?'等共 '.count($references).' 处':'').'。请先解除引用；历史修订引用会保留。',409);
                    $s['templates']=array_values(array_filter($s['templates'],fn ($row)=>$row['id']!==$id));
                    Store::event($s,'template.delete',$template['name']);
                    return true;
                });
            case 'template.save':
                $schema = $this->input['schema'] ?? null;
                if (!is_array($schema)) {
                    throw new ApiError('模板必须是 JSON 对象');
                }Plans::checkSchema($schema);
                $name = $this->text('name', 100);
                return $this->store->transaction(function (array &$s) use ($schema, $name) {
                    $id = Store::id($s, 'templates');
                    $s['templates'][] = ['id' => $id,'name' => $name,'schema' => $schema];
                    return $id;
                });
            case 'plan.save':
                $name = $this->text('name', 100);
                $clients = $this->text('clients', 200, false);
                $template = $this->id('template_id');
                $targets = array_values(array_unique(array_map('intval', $this->input['targets'] ?? [])));
                $id = (int)($this->input['id'] ?? 0);
                return $this->store->transaction(function (array &$s) use ($name, $clients, $template, $targets, $id) {
                    Store::find($s, 'templates', $template);
                    if ($id) {
                        Store::find($s, 'plans', $id);
                    }
                    foreach ($targets as $target) {
                        $selected = Store::find($s, 'targets', $target);
                        foreach ($s['plans'] as $plan) {
                            if ($plan['id'] !== $id) {
                                foreach ($plan['targets'] as $other) {
                                    $existing = Store::find($s, 'targets', $other);
                                    if ($other === $target || [$existing['account_id'],$existing['region'],$existing['bucket'],$existing['key']] === [$selected['account_id'],$selected['region'],$selected['bucket'],$selected['key']]) {
                                        throw new ApiError('发布地址已属于方案「'.$plan['name'].'」，请使用独立对象路径');
                                    }
                                }
                            }
                        }
                    }
                    $row = ['id' => $id ?: Store::id($s, 'plans'),'name' => $name,'clients' => $clients,'template_id' => $template,'targets' => $targets];
                    Store::put($s, 'plans', $row);
                    return $row['id'];
                });
            case 'config.diff':
                $s = $this->store->read();
                $to = Store::find($s, 'versions', $this->id());
                $from = Store::find($s, 'versions', $this->id('from'));
                return Plans::diff($from['content'], $to['content']);
            case 'config.save':return $this->saveVersion();
            case 'config.clone':$id = $this->id();
                $newPlan = (int)($this->input['plan_id'] ?? 0);
                return $this->store->transaction(function (array &$s) use ($id, $newPlan) {
                    $r = Store::find($s, 'versions', $id);
                    $r['parent_id'] = $r['id'];
                    if ($newPlan) {
                        Store::find($s, 'plans', $newPlan);
                        $r['plan_id'] = $newPlan;
                    }
                    $r['revision'] = 1 + max(array_merge([0], array_column(array_filter($s['versions'], fn ($v) => ($v['plan_id'] ?? 1) === ($r['plan_id'] ?? 1)), 'revision')));
                    $r['id'] = Store::id($s, 'versions');
                    $r['version'] = $r['id'];
                    if (($r['legacy_validation'] ?? false) && array_key_exists('config_version', $r['content'])) {
                        $r['content']['config_version'] = (string)$r['version'];
                    }
                    $r['status'] = 'draft';
                    $r['created_at'] = time();
                    $r['summary'] = '复制版本';
                    $s['versions'][] = $r;
                    return $r['id'];
                });
            case 'config.delete':$id = $this->id();
                return $this->store->transaction(function (array &$s) use ($id) {
                    $r = Store::find($s, 'versions', $id);
                    if ($r['status'] !== 'draft') {
                        throw new ApiError('仅允许删除草稿');
                    }
                    $s['versions'] = array_values(array_filter($s['versions'], fn ($r) => $r['id'] !== $id));
                    return true;
                });
            case 'config.settings':return $this->settings();
            case 'config.preview':$s = $this->store->read();
                $r = Store::find($s, 'versions', $this->id());
                $payload = $this->configs->encode($r, $s['settings']);
                return ['payload' => $payload,'bytes' => strlen($payload),'checksum' => hash('sha256', $payload)];
            case 'config.publish':return $this->publish();
            case 'config.retry':return (new Releases($this->store,$this->security,$this->objects,$this->configs))->run(0,[],$this->id());
            case 'config.check':$s = $this->store->read();
                Configs::preflight(Store::find($s,'versions',$this->id()));
                return ['valid'=>true];
            case 'backup.local':
                $name = $this->text('name',100);
                if (!preg_match('/^before-restore-[0-9]+-[a-f0-9]{12}\.php$/',$name)) throw new ApiError('备份名称无效');
                $path = $this->store->directory().'/backups/'.$name;
                if (!is_file($path)) throw new ApiError('备份不存在',404);
                $raw = file_get_contents($path);
                if (!str_starts_with($raw,Store::GUARD)) throw new ApiError('备份格式错误');
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="before-restore.json"');
                header('Cache-Control: no-store');
                echo substr($raw,strlen(Store::GUARD));
                exit;
            case 'backup':$s = $this->store->read();
                unset($s['rates']);
                $key = include $this->store->directory().'/key.php';
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="cloud-desk-backup.json"');
                echo json_encode(['format' => 'cloud-desk-v2','key' => $key,'state' => $s], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            case 'restore':return $this->restore();
            case 'import.v1':return $this->importV1();
            default:throw new ApiError('接口不存在', 404);
        }
        } finally { if (is_resource($exclusive)) fclose($exclusive); }
    }
    private function provider(array $a, string $provider): void
    {
        if ($a['provider'] !== $provider) {
            throw new ApiError('请选择对应云厂商的账号');
        }
    }
    private function file(int $limit): string
    {
        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > $limit || !is_uploaded_file($file['tmp_name'])) {
            throw new ApiError('上传失败，请检查文件大小和 PHP 上传限制');
        }
        return file_get_contents($file['tmp_name']);
    }
    private function gtm(string $action): array
    {
        $account=$this->account();$this->provider($account,'aliyun');
        $gtm=new Gtm($this->cloud,$this->store,$this->security);
        $started=hrtime(true);
        try {
            if ($action==='gtm.catalog') return Gtm::catalog();
            if ($action==='gtm.options') return $gtm->options($account,$this->text('operation',100,false) ?: null,($this->input['unified'] ?? '')==='true',$this->text('section',30,false) ?: null);
            if ($action==='gtm.history') return array_values(array_reverse(array_filter($this->store->read()['gtm_changes'] ?? [],fn ($row)=>$row['account_id']===$account['id'])));
            if ($action==='gtm.action') return $gtm->apply($account,$this->text('ticket',1000000));
            if ($action==='gtm.preview') {
                if (isset($this->input['steps'])) {
                    if (!is_array($this->input['steps'])) throw new ApiError('变更步骤无效');
                    return $gtm->previewBatch($account,$this->input['steps'],$this->text('expected_token',100,false) ?: null);
                }
                $parameters=$this->input['parameters'] ?? null;
                if (!is_array($parameters)) throw new ApiError('GTM 参数无效');
                return $gtm->preview($account,$this->text('operation',100),$parameters,$this->text('expected_token',100,false) ?: null);
            }
            $kind=$this->text('kind',30,false) ?: 'list';
            if ($action==='gtm.resource' || $action==='gtm.detail') return $gtm->detail($account,$kind,$this->text('resource',100,false) ?: $this->text('config',100),$this->text('instance_id',100,false));
            if ($action==='gtm.references') return $gtm->references($account,$kind,$this->text('resource',100));
            $kind=match($action) {'gtm.list'=>'list','gtm.pools'=>'pools','gtm.addresses'=>'addresses','gtm.monitors'=>'monitors',default=>throw new ApiError('GTM 接口无效')};
            return $gtm->listing($account,$kind,max(1,(int)($this->input['page'] ?? 1)));
        } finally {
            $metrics=$gtm->metrics();
            header(sprintf('Server-Timing: gtm;dur=%.2f, cloud;dur=%.2f;desc="%d calls"',(hrtime(true)-$started)/1000000,$metrics['cloud_ms'],$metrics['cloud_calls']));
        }
    }
    private function saveVersion(): int
    {
        Plans::migrate($this->store);
        $id = (int)($this->input['id'] ?? 0);
        $content = $this->input['content'] ?? null;
        if (!is_array($content) || ($content !== [] && array_is_list($content))) {
            throw new ApiError('客户端配置必须是 JSON 对象');
        }
        $mode = $this->text('mode', 30);
        if (!in_array($mode, ['plain','xor_base64','signed_xor_v2'], true)) {
            throw new ApiError('加密模式无效');
        }
        $summary = $this->text('summary', 500, false);
        return $this->store->transaction(function (array &$s) use ($id, $content, $mode, $summary) {
            $r = $id ? Store::find($s, 'versions', $id) : ['id' => Store::id($s, 'versions'),'created_at' => time(),'status' => 'draft'];
            if ($id && (!is_string($this->input['expected_token'] ?? null) || !hash_equals(Checks::token($r),$this->input['expected_token']))) {
                throw new ApiError('此修订已变化，请保留当前编辑并刷新后对比，避免覆盖其他修改',409);
            }
            if ($r['status'] !== 'draft') {
                $r['parent_id'] = $r['id'];
                $r['id'] = Store::id($s, 'versions');
                $r['version'] = $r['id'];
                $r['status'] = 'draft';
                $r['created_at'] = time();
                $r['revision'] = 1 + max(array_merge([0], array_column(array_filter($s['versions'], fn ($v) => ($v['plan_id'] ?? 1) === ($r['plan_id'] ?? 1)), 'revision')));
            }
            $planId = $r['plan_id'] ?? (int)($this->input['plan_id'] ?? 1);
            $plan = Store::find($s, 'plans', $planId);
            $r['plan_id'] = $planId;
            $templateId = (int)($this->input['template_id'] ?? ($r['template_id'] ?? $plan['template_id']));
            if (!isset($r['schema']) || $templateId !== ($r['template_id'] ?? null)) {
                $template = Store::find($s, 'templates', $templateId);
                $r['schema'] = $template['schema'];
                $r['template_id'] = $templateId;
                $r['legacy_validation'] = $template['legacy_validation'] ?? false;
                $r['oss_validation'] = $template['oss_validation'] ?? false;
            }
            if (array_key_exists('schema', $this->input)) {
                $schema = $this->input['schema'];
                if (!is_array($schema)) {
                    throw new ApiError('修订结构格式无效');
                }
                Plans::checkSchema($schema);
                $r['schema'] = $schema;
            }
            if (array_key_exists('field_order', $this->input)) {
                $order = $this->input['field_order'];
                if (!is_array($order) || count($order) > 2000) {
                    throw new ApiError('字段排序格式无效');
                }
                foreach ($order as $path => $keys) {
                    $parts = json_decode((string)$path, true);
                    if (!is_array($parts) || !array_is_list($parts) || count($parts) > 32 || array_filter($parts, fn ($v) => !is_string($v)) || !is_array($keys) || !array_is_list($keys) || count($keys) > 2000 || array_filter($keys, fn ($v) => !is_string($v)) || count(array_unique($keys)) !== count($keys)) {
                        throw new ApiError('字段排序格式无效');
                    }
                }
                $r['field_order'] = $order;
            }
            if (($r['legacy_validation'] ?? false) || ($r['oss_validation'] ?? false)) {
                Configs::validate($content);
            }
            $r['revision'] ??= 1 + max(array_merge([0], array_column(array_filter($s['versions'], fn ($v) => ($v['plan_id'] ?? 1) === $planId), 'revision')));
            Plans::validate($content, $r['schema']);
            if (($r['legacy_validation'] ?? false) && array_key_exists('config_version', $content)) {
                $content['config_version'] = (string)($r['version'] ?? $r['id']);
            }
            $r = array_merge($r, ['version' => $r['version'] ?? $r['id'],'content' => $content,'mode' => $mode,'summary' => $summary]);
            Store::put($s, 'versions', $r);
            Store::event($s, 'config.save', 'v'.$r['version']);
            return $r['id'];
        });
    }
    private function settings(): array
    {
        return $this->store->transaction(function (array &$s) {
            $xor = $this->text('xor_key', 4000, false);
            if ($xor !== '') {
                $s['settings']['xor_key'] = $this->security->encrypt($xor);
            }
            if (!empty($this->input['generate_signing'])) {
                if (!function_exists('sodium_crypto_sign_keypair')) {
                    throw new ApiError('Ed25519 签名需要 PHP sodium 扩展');
                }
                $pair = sodium_crypto_sign_keypair();
                $s['settings']['signing_public'] = base64_encode(sodium_crypto_sign_publickey($pair));
                $s['settings']['signing_secret'] = $this->security->encrypt(base64_encode(sodium_crypto_sign_secretkey($pair)));
            }
            return ['signing_public' => $s['settings']['signing_public'] ?? null];
        });
    }
    private function publish(): array
    {
        Plans::migrate($this->store);
        return (new Releases($this->store,$this->security,$this->objects,$this->configs))->run($this->id(),array_values(array_unique(array_map('intval',(array)($this->input['targets'] ?? [])))));
    }
    private function restore(): bool
    {
        $this->confirmation('恢复备份');
        $backup = json_decode($this->file(20 * 1024 * 1024), true, 512, JSON_THROW_ON_ERROR);
        if (($backup['format'] ?? '') !== 'cloud-desk-v2' || strlen(base64_decode($backup['key'] ?? '', true) ?: '') !== 32 || !is_array($backup['state'] ?? null)) {
            throw new ApiError('备份格式错误');
        }
        Checks::state($backup['state']);
        // Re-encrypt using the installation key; never replace the key before a state commit.
        $source = new Security($backup['key']);
        $data = $backup['state'];
        $data['gtm_changes'] ??= [];
        foreach (['accounts','targets','versions','publications','settings','events'] as $table) {
            if (!isset($data[$table]) || !is_array($data[$table])) {
                throw new ApiError('备份缺少数据表：'.$table);
            }
        }
        foreach ($data['accounts'] as &$a) {
            foreach ($a['credentials'] as &$value) {
                $value = $this->security->encrypt($source->decrypt($value));
            }
        }
        unset($a,$value);
        foreach (['xor_key','signing_secret'] as $field) {
            if (!empty($data['settings'][$field])) {
                $data['settings'][$field] = $this->security->encrypt($source->decrypt($data['settings'][$field]));
            }
        }
        foreach (['notification_channels'=>'token','notification_sources'=>'secret'] as $table=>$field) {
            $data[$table] ??=[];
            foreach ($data[$table] as &$row) {
                $plain=$source->decrypt($row[$field]);
                if (!preg_match($field==='secret'?'/^[a-f0-9]{64}$/':'/^[0-9]{5,}:[A-Za-z0-9_-]{20,}$/',$plain)) throw new ApiError('通知凭证备份格式无效');
                $row[$field]=$this->security->encrypt($plain);
            }unset($row);
        }
        $data['notification_jobs'] ??=[];
        foreach ($data['notification_jobs'] as &$notification) if ($notification['status']==='sending') { $notification['status']='retry';$notification['next_at']=time(); }unset($notification);
        foreach ($data['publications'] as &$job) {
            if (!empty($job['frozen'])) {
                $frozen = json_decode($source->decrypt($job['frozen']),true,512,JSON_THROW_ON_ERROR);
                foreach ($frozen['account']['credentials'] as &$credential) $credential = $this->security->encrypt($source->decrypt($credential));
                unset($credential);
                $job['frozen'] = $this->security->encrypt(json_encode($frozen,JSON_THROW_ON_ERROR));
            }
            if ($job['status'] === 'publishing') { $job['status']='failed'; $job['error']='备份中的未完成任务，请重试'; }
        }
        unset($job);
        foreach ($data['versions'] as &$version) if (($version['status'] ?? '') === 'publishing') $version['status'] = 'partial';
        unset($version);
        $this->store->transaction(function (array &$s) use ($data) {
            $directory = $this->store->directory().'/backups';
            if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new ApiError('无法创建恢复前备份');
            $path = $directory.'/before-restore-'.time().'-'.bin2hex(random_bytes(6)).'.php';
            $key = include $this->store->directory().'/key.php';
            $payload = Store::GUARD.json_encode(['format'=>'cloud-desk-v2','key'=>$key,'state'=>$s],JSON_THROW_ON_ERROR | JSON_HEX_TAG);
            if (file_put_contents($path,$payload,LOCK_EX) !== strlen($payload)) throw new ApiError('恢复前备份失败，已停止恢复');
            chmod($path,0600);

            if (!isset($data['plans'],$data['templates'])) {
                unset($s['plans'],$s['templates']);
            }
            foreach (['accounts','targets','versions','publications','settings','events','sequences','plans','templates','gtm_changes','notification_channels','notification_sources','notification_jobs'] as $table) {
                if (isset($data[$table])) {
                    $s[$table] = $data[$table];
                }
            }Store::event($s, 'restore', '备份');
        });
        return true;
    }
    private function importV1(): array
    {
        $this->confirmation('导入旧版');
        $key = $this->text('legacy_key', 500);
        $legacy = json_decode($this->file(20 * 1024 * 1024), true, 512, JSON_THROW_ON_ERROR);
        $result = (new Importer($this->security))->run($this->store, $legacy, $key);
        $this->store->transaction(function (array &$s) {
            unset($s['plans'],$s['templates']);
        });
        Plans::migrate($this->store);
        return $result;
    }
}

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
        unset($state['admin'],$state['rates'],$state['sequences']);
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
            $reads = ['boot','overview','regions','instances','buckets','objects','cf.zones','cf.records','dns.list','gtm.list','gtm.detail','gtm.pools','gtm.addresses','gtm.monitors'];
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
                $this->respond(['installed' => isset($state['admin']),'authenticated' => $authenticated,'csrf' => $csrf,'email' => $authenticated ? $state['admin']['email'] : null,'version' => '2.1.0','environment' => ['php' => PHP_VERSION,'missing' => array_values(array_filter(['openssl','curl','simplexml'], fn ($e) => !extension_loaded($e)))]]);
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
        } catch (\Throwable $e) {
            $this->respond(null, 422, $e instanceof \RuntimeException ? $e->getMessage() : '操作失败，请检查输入与服务器运行环境');
        }
    }
    private function respond(mixed $data, int $status = 200, string $error = ''): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['data' => $data,'error' => $error], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    private function dispatch(string $action): mixed
    {
        switch ($action) {
            case 'overview':Plans::migrate($this->store);
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
                return $this->cloud->rpc($a, $domain ? 'DescribeDomainRecords' : 'DescribeDomains', $params, service:'alidns');
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
            case 'gtm.list':case 'gtm.detail':case 'gtm.pools':case 'gtm.addresses':case 'gtm.monitors':case 'gtm.action':return $this->gtm($action);
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
            case 'config.retry':$s = $this->store->read();
                $p = Store::find($s, 'publications', $this->id());
                $this->input['id'] = $p['version_id'];
                $this->input['targets'] = [$p['target_id']];
                return $this->publish();
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
        $a = $this->account();
        $this->provider($a, 'aliyun');
        $map = ['gtm.list' => 'ListCloudGtmInstanceConfigs','gtm.detail' => 'DescribeCloudGtmInstanceConfigFullInfo','gtm.pools' => 'ListCloudGtmAddressPools','gtm.addresses' => 'ListCloudGtmAddresses','gtm.monitors' => 'ListCloudGtmMonitorTemplates'];
        if ($action === 'gtm.action') {
            $operation = $this->text('operation', 100);
            $allowed = ['UpdateCloudGtmInstanceConfigBasic','UpdateCloudGtmInstanceConfigLbStrategy','UpdateCloudGtmInstanceConfigEnableStatus','ReplaceCloudGtmInstanceConfigAddressPool','CreateCloudGtmAddressPool','UpdateCloudGtmAddressPoolBasicConfig','UpdateCloudGtmAddressPoolRemark','UpdateCloudGtmAddressPoolEnableStatus','UpdateCloudGtmAddressPoolLbStrategy','DeleteCloudGtmAddressPool','ReplaceCloudGtmAddressPoolAddress','CreateCloudGtmAddress','UpdateCloudGtmAddress','UpdateCloudGtmAddressRemark','UpdateCloudGtmAddressEnableStatus','UpdateCloudGtmAddressManualAvailableStatus','DeleteCloudGtmAddress'];
            if (!in_array($operation, $allowed, true)) {
                throw new ApiError('GTM 操作无效');
            }
            $this->confirmation($operation);
            $params = $this->input['parameters'] ?? [];
            if (!is_array($params)) {
                throw new ApiError('GTM 参数必须为 JSON 对象');
            }
            if (str_starts_with($operation, 'Replace')) {
                $params['ClientToken'] = bin2hex(random_bytes(16));
            }
        } else {
            $operation = $map[$action];
            $params = ['PageNumber' => max(1, (int)($this->input['page'] ?? 1)),'PageSize' => 100,'AcceptLanguage' => 'zh-CN'];
            if ($action === 'gtm.detail') {
                $params = ['InstanceId' => $this->text('instance', 100),'ConfigId' => $this->text('config', 100)];
            }
        }
        $result = $this->cloud->rpc($a, $operation, $params, service:'alidns');
        if ($action === 'gtm.action') {
            $this->record($operation, $a['name']);
        }
        return $result;
    }
    private function saveVersion(): int
    {
        Plans::migrate($this->store);
        $id = (int)($this->input['id'] ?? 0);
        $content = $this->input['content'] ?? null;
        if (!is_array($content)) {
            throw new ApiError('客户端配置必须是 JSON 对象');
        }
        $mode = $this->text('mode', 30);
        if (!in_array($mode, ['plain','xor_base64','signed_xor_v2'], true)) {
            throw new ApiError('加密模式无效');
        }
        $summary = $this->text('summary', 500, false);
        return $this->store->transaction(function (array &$s) use ($id, $content, $mode, $summary) {
            $r = $id ? Store::find($s, 'versions', $id) : ['id' => Store::id($s, 'versions'),'created_at' => time(),'status' => 'draft'];
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
            }
            if ($r['legacy_validation'] ?? false) {
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
        $id = $this->id();
        $targetIds = array_values(array_unique(array_map('intval', (array)($this->input['targets'] ?? []))));
        if (!$targetIds) {
            throw new ApiError('请选择发布目标');
        }
        $snapshot = $this->store->read();
        Plans::migrate($this->store);
        $snapshot = $this->store->read();
        $version = Store::find($snapshot, 'versions', $id);
        $plan = Store::find($snapshot, 'plans', $version['plan_id'] ?? 1);
        if (array_diff($targetIds, $plan['targets'])) {
            throw new ApiError('发布目标未绑定当前方案，请先在方案设置中选择独立路径');
        }
        foreach ($targetIds as $targetId) {
            $target = Store::find($snapshot, 'targets', $targetId);
            foreach ($snapshot['plans'] as $otherPlan) {
                if ($otherPlan['id'] !== $plan['id']) {
                    foreach ($otherPlan['targets'] as $otherId) {
                        $other = Store::find($snapshot, 'targets', $otherId);
                        if ([$target['region'],$target['bucket'],$target['key']] === [$other['region'],$other['bucket'],$other['key']] && Store::find($snapshot, 'accounts', $target['account_id'])['provider'] === Store::find($snapshot, 'accounts', $other['account_id'])['provider']) {
                            throw new ApiError('发布路径与其他方案冲突，请先修改目标路径');
                        }
                    }
                }
            }
        }
        $payload = $this->configs->encode($version, $snapshot['settings']);
        $checksum = hash('sha256', $payload);
        $jobs = $this->store->transaction(function (array &$s) use ($id, $targetIds, $checksum, $version) {
            $r = Store::find($s, 'versions', $id);
            if ($r['content'] !== $version['content'] || $r['mode'] !== $version['mode']) {
                throw new ApiError('配置已发生变化，请重新预览后发布');
            }
            foreach ($s['publications'] as $p) {
                if ($p['version_id'] === $id && $p['status'] === 'publishing' && $p['started_at'] > time() - 300) {
                    throw new ApiError('此版本正在发布，请稍后重试');
                }
            }
            $r['status'] = 'publishing';
            Store::put($s, 'versions', $r);
            $jobs = [];
            foreach ($targetIds as $targetId) {
                $t = Store::find($s, 'targets', $targetId);
                if (!$t['enabled']) {
                    throw new ApiError('目标「'.$t['name'].'」已停用');
                }
                $p = ['id' => Store::id($s, 'publications'),'version_id' => $id,'target_id' => $targetId,'target_name' => $t['name'],'status' => 'publishing','checksum' => $checksum,'started_at' => time(),'error' => ''];
                $s['publications'][] = $p;
                $jobs[] = $p;
            }
            $s['publications'] = array_slice($s['publications'], -1000);
            return $jobs;
        });
        foreach ($jobs as &$job) {
            $target = Store::find($snapshot, 'targets', $job['target_id']);
            $a = Store::find($snapshot, 'accounts', $target['account_id']);
            try {
                $key = $target['key'];
                $dot = strrpos($key, '.');
                $versionKey = $dot === false ? $key.'.v'.$version['version'] : substr($key, 0, $dot).'.v'.$version['version'].substr($key, $dot);
                $this->objects->request($a, 'PUT', $target['region'], $target['bucket'], $versionKey, body:$payload, publicRead:$target['public_read']);
                $this->objects->request($a, 'PUT', $target['region'], $target['bucket'], $key, body:$payload, publicRead:$target['public_read']);
                $check = $this->objects->request($a, 'GET', $target['region'], $target['bucket'], $key);
                if (!hash_equals($checksum, hash('sha256', $check['body']))) {
                    throw new ApiError('发布后读取校验失败');
                }
                $job['status'] = 'success';
            } catch (\Throwable $e) {
                $job['status'] = 'failed';
                $job['error'] = $e instanceof \RuntimeException ? $e->getMessage() : '发布失败';
            }
            $job['finished_at'] = time();
            $this->store->transaction(function (array &$s) use ($job) {
                Store::put($s, 'publications', $job);
                Store::event($s, 'config.publish', $job['target_name'], $job['status']);
            });
        }
        unset($job);
        $this->store->transaction(function (array &$s) use ($id) {
            $r = Store::find($s, 'versions', $id);
            $latest = [];
            foreach ($s['publications'] as $p) {
                if ($p['version_id'] === $id) {
                    $latest[$p['target_id']] = $p;
                }
            }
            $all = $latest && !array_filter($latest, fn ($p) => $p['status'] !== 'success');
            $r['status'] = $all ? 'published' : 'partial';
            if ($all) {
                foreach ($s['versions'] as &$old) {
                    if ($old['id'] !== $id && ($old['plan_id'] ?? 1) === ($r['plan_id'] ?? 1) && $old['status'] === 'published') {
                        $old['status'] = 'archived';
                    }
                }
                unset($old);
                $r['published_at'] = time();
            }Store::put($s, 'versions', $r);
        });
        return $jobs;
    }
    private function restore(): bool
    {
        $this->confirmation('恢复备份');
        $backup = json_decode($this->file(20 * 1024 * 1024), true, 512, JSON_THROW_ON_ERROR);
        if (($backup['format'] ?? '') !== 'cloud-desk-v2' || strlen(base64_decode($backup['key'] ?? '', true) ?: '') !== 32 || !is_array($backup['state'] ?? null)) {
            throw new ApiError('备份格式错误');
        }
        // Re-encrypt using the installation key; never replace the key before a state commit.
        $source = new Security($backup['key']);
        $data = $backup['state'];
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
        $this->store->transaction(function (array &$s) use ($data) {
            if (!isset($data['plans'],$data['templates'])) {
                unset($s['plans'],$s['templates']);
            }
            foreach (['accounts','targets','versions','publications','settings','events','sequences','plans','templates'] as $table) {
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

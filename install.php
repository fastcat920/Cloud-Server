<?php
// CLI-only initializer; upload the release archive with vendor already included.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(__DIR__);
$errors = [];
if (version_compare(PHP_VERSION, '8.2.0', '<')) $errors[] = '需要 PHP 8.2 或更高版本';
foreach (['mbstring','openssl','curl','fileinfo','tokenizer','xml','simplexml','ctype','bcmath'] as $extension) {
    if (!extension_loaded($extension)) $errors[] = '缺少 PHP 扩展：' . $extension;
}
if (!is_file('vendor/autoload.php')) $errors[] = '部署包缺少 vendor 依赖目录';
if (!is_file('public/assets/admin-app/.vite/manifest.json')) $errors[] = '部署包缺少页面构建文件';
foreach (['storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','storage/app/private','bootstrap/cache'] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) $errors[] = '无法创建目录：' . $directory;
    if (!is_writable($directory)) $errors[] = '目录不可写：' . $directory;
}
if ($errors) { fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL); exit(1); }
echo "运行环境检查通过\n";
if (in_array('--check', $argv, true)) exit(0);
function ask($label) { echo $label; return trim((string) fgets(STDIN)); }
if (!is_file('.env')) {
    $url = ask('网站地址（例如 https://cloud.example.com）：');
    if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'], true) || preg_match('/[\s"\\\\]/', $url)) {
        fwrite(STDERR, "网站地址无效\n"); exit(1);
    }
    $environment = str_replace(['APP_KEY=', 'APP_URL=https://cloud.example.com'], ['APP_KEY=base64:' . base64_encode(random_bytes(32)), 'APP_URL=' . rtrim($url,'/')], file_get_contents('.env.example'));
    $handle = @fopen('.env', 'x');
    if (!$handle) { fwrite(STDERR, "无法创建 .env，请检查权限\n"); exit(1); }
    chmod('.env',0600);
    if (fwrite($handle,$environment) !== strlen($environment)) { fclose($handle); fwrite(STDERR,"写入配置失败\n"); exit(1); }
    fclose($handle);
    echo "已生成网站配置与加密密钥\n";
} else {
    echo "保留现有 .env 和加密密钥\n";
    if (!preg_match('/^APP_KEY=\S+/m',file_get_contents('.env'))) { fwrite(STDERR,"现有 .env 缺少 APP_KEY，请先补齐，已有数据时不要重新生成密钥\n"); exit(1); }
}
$email = ask('管理员邮箱（已有账号将重置密码）：');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { fwrite(STDERR,"邮箱格式错误\n"); exit(1); }
// Password is entered by Artisan through the terminal, never passed as a shell argument.
passthru(escapeshellarg(PHP_BINARY) . ' artisan cloud:admin ' . escapeshellarg($email), $status);
if ($status !== 0) exit($status);
echo "初始化完成。宝塔运行目录设为 public，访问 /admin 登录。\n";

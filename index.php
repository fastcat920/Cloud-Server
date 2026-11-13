<?php

declare(strict_types=1);
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
// Fingerprint the complete asset graph so updates also refresh imported modules.
$assetNames = ['app.js', 'ui.js', 'config-form.js', 'config-edit.js', 'app.css'];
$assetVersion = hash('sha256', implode('', array_map(fn ($name) => hash_file('sha256', __DIR__.'/assets/'.$name), $assetNames)));
if (isset($_GET['asset'])) {
    $name = $_GET['asset'];
    if (!is_string($name) || !in_array($name, $assetNames, true)) {
        http_response_code(404);
        exit;
    }
    $isJs = str_ends_with($name, '.js');
    header('Content-Type: '.($isJs ? 'text/javascript' : 'text/css').'; charset=utf-8');
    header('Cache-Control: no-cache');
    $body = file_get_contents(__DIR__.'/assets/'.$name);
    if ($isJs) {
        foreach ($assetNames as $dependency) {
            $body = str_replace('"./'.$dependency.'"', '"./index.php?asset='.$dependency.'&v='.$assetVersion.'"', $body);
        }
    }
    echo $body;
    exit;
}
if (isset($_GET['api'])) {
    foreach (['Store','Security','Cloud','Objects','Configs','Plans','Checks','Releases','Importer','Api'] as $file) {
        require_once __DIR__.'/_core/'.$file.'.php';
    }
    try {
        (new CloudDesk\Api(getenv('CLOUD_DESK_DATA') ?: __DIR__.'/_core/data'))->run();
    } catch (Throwable $e) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['data' => null,'error' => $e instanceof RuntimeException ? $e->getMessage() : '服务初始化失败，请检查 PHP 版本（8.2+）和目录权限'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo str_replace(['assets/app.css', 'assets/app.js'], ['index.php?asset=app.css&amp;v='.$assetVersion, 'index.php?asset=app.js&amp;v='.$assetVersion], file_get_contents(__DIR__.'/_core/view.php'));
